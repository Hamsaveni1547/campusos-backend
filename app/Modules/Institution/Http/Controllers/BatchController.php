<?php

namespace App\Modules\Institution\Http\Controllers;

use App\Models\User;
use App\Modules\Institution\Authorization\InstitutionPermissions;
use App\Modules\Institution\Enums\BatchStatus;
use App\Modules\Institution\Enums\RecordStatus;
use App\Modules\Institution\Http\Resources\AcademicResources;
use App\Modules\Institution\Models\Batch;
use App\Modules\Institution\Models\Programme;
use App\Modules\Institution\Models\Section;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Http\ApiResponse;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Batches (programme + admission year) and their sections.
 *
 * @tags Batches & sections
 */
class BatchController extends Controller
{
    /**
     * Batches. Filters: `filter[programme]` (id), `filter[department]` (id), `filter[admission_year]`, `filter[status]`.
     */
    public function index(Request $request): JsonResponse
    {
        $batches = Batch::query()
            ->with(['programme', 'sections.classAdvisor'])
            ->when($request->input('filter.programme'), fn ($query, $id) => $query->whereHas('programme', fn ($query) => $query->where('ulid', $id)))
            ->when($request->input('filter.department'), fn ($query, $id) => $query->whereHas('programme.department', fn ($query) => $query->where('ulid', $id)))
            ->when($request->input('filter.admission_year'), fn ($query, $year) => $query->where('admission_year', $year))
            ->when($request->input('filter.status'), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('admission_year')
            ->orderBy('name')
            ->get();

        return ApiResponse::list($batches, fn (Batch $batch): array => AcademicResources::batch($batch));
    }

    public function show(Batch $batch): JsonResponse
    {
        return ApiResponse::data(AcademicResources::batch($batch->load(['programme', 'sections.classAdvisor'])));
    }

    /**
     * Create a batch. Sections can be created at the same time, e.g. `"sections": ["A", "B"]`.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize(InstitutionPermissions::BatchesManage);

        $validated = $request->validate([
            'programme_id' => ['required', 'string', 'size:26'],
            'admission_year' => ['required', 'integer', 'min:1950', 'max:'.(now()->year + 1)],
            /** Defaults to "<programme code> <year>". */
            'name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'sections' => ['sometimes', 'array', 'max:26'],
            'sections.*' => ['string', 'max:20', 'distinct'],
        ]);

        $programme = Programme::query()->where('ulid', $validated['programme_id'])->first();

        if ($programme === null || $programme->status !== RecordStatus::Active) {
            throw new BusinessRuleException('Choose an active programme.', 'PROGRAMME_INVALID', 422);
        }

        if ($programme->batches()->where('admission_year', $validated['admission_year'])->exists()) {
            throw new BusinessRuleException('A batch already exists for this programme and year.', 'BATCH_EXISTS', 409);
        }

        $batch = Batch::query()->create([
            'programme_id' => $programme->id,
            'admission_year' => $validated['admission_year'],
            'name' => $validated['name'] ?? $programme->code.' '.$validated['admission_year'],
            'status' => BatchStatus::Active,
        ]);

        foreach ($validated['sections'] ?? [] as $name) {
            $batch->sections()->create(['name' => strtoupper($name)]);
        }

        return ApiResponse::data(AcademicResources::batch($batch->load(['programme', 'sections.classAdvisor'])), 201);
    }

    public function update(Request $request, Batch $batch): JsonResponse
    {
        Gate::authorize(InstitutionPermissions::BatchesManage);

        $batch->update($request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'status' => ['sometimes', Rule::enum(BatchStatus::class)],
        ]));

        return ApiResponse::data(AcademicResources::batch($batch->load(['programme', 'sections.classAdvisor'])));
    }

    public function destroy(Batch $batch): JsonResponse
    {
        Gate::authorize(InstitutionPermissions::BatchesManage);
        $batch->sections()->delete();
        $batch->delete();

        return ApiResponse::message('Batch deleted.');
    }

    public function storeSection(Request $request, Batch $batch): JsonResponse
    {
        Gate::authorize(InstitutionPermissions::BatchesManage);
        $validated = $request->validate($this->sectionRules($batch));

        $section = $batch->sections()->create($this->sectionAttributes($validated));

        return ApiResponse::data(AcademicResources::section($section->load('classAdvisor')), 201);
    }

    public function updateSection(Request $request, Section $section): JsonResponse
    {
        Gate::authorize(InstitutionPermissions::BatchesManage);
        $validated = $request->validate($this->sectionRules($section->batch, $section));

        $section->update($this->sectionAttributes($validated));

        return ApiResponse::data(AcademicResources::section($section->load('classAdvisor')));
    }

    public function destroySection(Section $section): JsonResponse
    {
        Gate::authorize(InstitutionPermissions::BatchesManage);
        $section->delete();

        return ApiResponse::message('Section deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function sectionRules(Batch $batch, ?Section $section = null): array
    {
        return [
            'name' => [$section === null ? 'required' : 'sometimes', 'string', 'max:20', Rule::unique('sections', 'name')->where('batch_id', $batch->id)->ignore($section?->id)],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:500'],
            /** User id of a teaching staff member. */
            'class_advisor_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function sectionAttributes(array $validated): array
    {
        if (isset($validated['name'])) {
            $validated['name'] = strtoupper($validated['name']);
        }

        if (array_key_exists('class_advisor_id', $validated) && $validated['class_advisor_id'] !== null) {
            $institutionId = app(CurrentInstitution::class)->id();
            $advisor = User::query()
                ->where('ulid', $validated['class_advisor_id'])
                ->whereHas('institutions', fn ($query) => $query->where('institutions.id', $institutionId))
                ->first();

            if ($advisor === null) {
                throw new BusinessRuleException('The class advisor must be a member of this institution.', 'ADVISOR_INVALID', 422);
            }

            $validated['class_advisor_id'] = $advisor->id;
        }

        return $validated;
    }
}
