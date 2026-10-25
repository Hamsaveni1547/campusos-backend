<?php

namespace App\Modules\Institution\Http\Controllers;

use App\Modules\Institution\Authorization\InstitutionPermissions;
use App\Modules\Institution\Enums\ProgrammeLevel;
use App\Modules\Institution\Enums\RecordStatus;
use App\Modules\Institution\Http\Resources\AcademicResources;
use App\Modules\Institution\Models\Department;
use App\Modules\Institution\Models\Programme;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Http\ApiResponse;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * @tags Programmes
 */
class ProgrammeController extends Controller
{
    /**
     * Programmes. Filters: `filter[department]` (id), `filter[level]`, `filter[status]`, `filter[search]`.
     */
    public function index(Request $request): JsonResponse
    {
        $programmes = Programme::query()
            ->with('department')
            ->when($request->input('filter.department'), fn ($query, $id) => $query->whereHas('department', fn ($query) => $query->where('ulid', $id)))
            ->when($request->input('filter.level'), fn ($query, $level) => $query->where('level', $level))
            ->when($request->input('filter.status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->input('filter.search'), fn ($query, $search) => $query->where(fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
            ->orderBy('name')
            ->get();

        return ApiResponse::list($programmes, fn (Programme $programme): array => $this->present($programme));
    }

    public function show(Programme $programme): JsonResponse
    {
        return ApiResponse::data($this->present($programme->load('department')));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(InstitutionPermissions::ProgrammesManage);
        $validated = $request->validate($this->rules());

        $validated['department_id'] = $this->activeDepartmentId($validated['department_id']);
        $validated['total_terms'] ??= $validated['duration_years'] * 2;

        $programme = Programme::query()->create($validated);

        return ApiResponse::data($this->present($programme->load('department')), 201);
    }

    public function update(Request $request, Programme $programme): JsonResponse
    {
        Gate::authorize(InstitutionPermissions::ProgrammesManage);
        $validated = $request->validate($this->rules($programme));

        if (isset($validated['department_id'])) {
            $validated['department_id'] = $this->activeDepartmentId($validated['department_id']);
        }

        $programme->update($validated);

        return ApiResponse::data($this->present($programme->load('department')));
    }

    public function destroy(Programme $programme): JsonResponse
    {
        Gate::authorize(InstitutionPermissions::ProgrammesManage);

        return ApiResponse::deleteUnlessUsed($programme, ['batches' => 'batches'], 'programme');
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?Programme $programme = null): array
    {
        $required = $programme === null ? 'required' : 'sometimes';

        return [
            'department_id' => [$required, 'string', 'size:26'],
            'code' => [$required, 'string', 'max:20', 'alpha_dash', Rule::unique('programmes', 'code')->where('institution_id', app(CurrentInstitution::class)->id())->ignore($programme?->id)],
            'name' => [$required, 'string', 'max:200'],
            'level' => [$required, Rule::enum(ProgrammeLevel::class)],
            'duration_years' => [$required, 'integer', 'min:1', 'max:7'],
            /** Number of semesters/terms. Defaults to duration × 2. */
            'total_terms' => ['sometimes', 'integer', 'min:1', 'max:21'],
            'intake_capacity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5000'],
            /** Scheme / regulation year, e.g. "2022 Scheme". */
            'regulation' => ['sometimes', 'nullable', 'string', 'max:50'],
            'status' => ['sometimes', Rule::enum(RecordStatus::class)],
        ];
    }

    private function activeDepartmentId(string $ulid): int
    {
        $department = Department::query()->where('ulid', $ulid)->first();

        if ($department === null || $department->status !== RecordStatus::Active) {
            throw new BusinessRuleException('Choose an active department.', 'DEPARTMENT_INVALID', 422);
        }

        return $department->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Programme $programme): array
    {
        return [
            'id' => $programme->ulid,
            'code' => $programme->code,
            'name' => $programme->name,
            'level' => $programme->level->value,
            'duration_years' => $programme->duration_years,
            'total_terms' => $programme->total_terms,
            'intake_capacity' => $programme->intake_capacity,
            'regulation' => $programme->regulation,
            'status' => $programme->status->value,
            'department' => AcademicResources::department($programme->department),
        ];
    }
}
