<?php

namespace App\Modules\Admissions\Http\Controllers;

use App\Modules\Admissions\Authorization\AdmissionsPermissions;
use App\Modules\Admissions\Enums\CycleStatus;
use App\Modules\Admissions\Enums\Quota;
use App\Modules\Admissions\Http\AdmissionPresenter;
use App\Modules\Admissions\Models\AdmissionCycle;
use App\Modules\Admissions\Models\AdmissionSeat;
use App\Modules\Institution\Enums\RecordStatus;
use App\Modules\Institution\Models\AcademicYear;
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
 * @tags Admissions: cycles & seats
 */
class AdmissionCycleController extends Controller
{
    /**
     * Admission cycles. Filter: `filter[status]`.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(AdmissionsPermissions::ApplicationsView);

        $cycles = AdmissionCycle::query()
            ->with('academicYear')
            ->withCount('applications')
            ->when($request->input('filter.status'), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('opens_on')
            ->get();

        return ApiResponse::list($cycles, fn (AdmissionCycle $cycle): array => AdmissionPresenter::cycle($cycle));
    }

    public function show(AdmissionCycle $admissionCycle): JsonResponse
    {
        Gate::authorize(AdmissionsPermissions::ApplicationsView);

        return ApiResponse::data(AdmissionPresenter::cycle($admissionCycle->load(['academicYear', 'seats.programme'])->loadCount('applications'), withSeats: true));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(AdmissionsPermissions::CyclesManage);
        $validated = $request->validate($this->rules());

        $cycle = AdmissionCycle::query()->create($this->attributes($validated));

        return ApiResponse::data(AdmissionPresenter::cycle($cycle->load(['academicYear', 'seats.programme']), withSeats: true), 201);
    }

    public function update(Request $request, AdmissionCycle $admissionCycle): JsonResponse
    {
        Gate::authorize(AdmissionsPermissions::CyclesManage);
        $this->assertNotClosed($admissionCycle);
        $validated = $request->validate($this->rules($admissionCycle));

        $admissionCycle->update($this->attributes($validated, $admissionCycle));

        return ApiResponse::data(AdmissionPresenter::cycle($admissionCycle->load(['academicYear', 'seats.programme']), withSeats: true));
    }

    public function destroy(AdmissionCycle $admissionCycle): JsonResponse
    {
        Gate::authorize(AdmissionsPermissions::CyclesManage);

        return ApiResponse::deleteUnlessUsed($admissionCycle, ['applications' => 'applications'], 'admission cycle');
    }

    /**
     * Start taking applications. Needs at least one seat row.
     */
    public function open(AdmissionCycle $admissionCycle): JsonResponse
    {
        Gate::authorize(AdmissionsPermissions::CyclesManage);

        if (! $admissionCycle->seats()->exists()) {
            throw new BusinessRuleException('Add seats to the cycle before opening it.', 'NO_SEATS', 422);
        }

        $admissionCycle->update(['status' => CycleStatus::Open]);

        return $this->show($admissionCycle);
    }

    /**
     * Stop taking new applications. Applications already in progress continue.
     */
    public function close(AdmissionCycle $admissionCycle): JsonResponse
    {
        Gate::authorize(AdmissionsPermissions::CyclesManage);
        $admissionCycle->update(['status' => CycleStatus::Closed]);

        return $this->show($admissionCycle);
    }

    /**
     * Add a seat matrix row (programme + quota).
     */
    public function storeSeat(Request $request, AdmissionCycle $admissionCycle): JsonResponse
    {
        Gate::authorize(AdmissionsPermissions::CyclesManage);
        $this->assertNotClosed($admissionCycle);

        $validated = $request->validate([
            'programme_id' => ['required', 'string', 'size:26'],
            'quota' => ['required', Rule::enum(Quota::class)],
            'total' => ['required', 'integer', 'min:1', 'max:5000'],
            'min_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'admission_fee' => ['sometimes', 'numeric', 'min:0', 'max:10000000'],
        ]);

        $programme = Programme::query()->where('ulid', $validated['programme_id'])->where('status', RecordStatus::Active)->first();

        if ($programme === null) {
            throw new BusinessRuleException('Choose an active programme.', 'PROGRAMME_INVALID', 422);
        }

        if ($admissionCycle->seats()->where('programme_id', $programme->id)->where('quota', $validated['quota'])->exists()) {
            throw new BusinessRuleException('This programme already has seats under that quota.', 'SEAT_ROW_EXISTS', 409);
        }

        $seat = $admissionCycle->seats()->create(array_merge($validated, ['programme_id' => $programme->id]));

        return ApiResponse::data(AdmissionPresenter::seat($seat->load('programme')), 201);
    }

    public function updateSeat(Request $request, AdmissionSeat $admissionSeat): JsonResponse
    {
        Gate::authorize(AdmissionsPermissions::CyclesManage);
        $this->assertNotClosed($admissionSeat->cycle);

        $validated = $request->validate([
            'total' => ['sometimes', 'integer', 'min:1', 'max:5000'],
            'min_percentage' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'admission_fee' => ['sometimes', 'numeric', 'min:0', 'max:10000000'],
        ]);

        if (isset($validated['total']) && $validated['total'] < $admissionSeat->filled) {
            throw new BusinessRuleException('Seats cannot be fewer than the '.$admissionSeat->filled.' already offered.', 'SEATS_BELOW_FILLED', 422);
        }

        $admissionSeat->update($validated);

        return ApiResponse::data(AdmissionPresenter::seat($admissionSeat->load('programme')));
    }

    public function destroySeat(AdmissionSeat $admissionSeat): JsonResponse
    {
        Gate::authorize(AdmissionsPermissions::CyclesManage);

        return ApiResponse::deleteUnlessUsed($admissionSeat, ['applications' => 'applications'], 'seat row');
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?AdmissionCycle $cycle = null): array
    {
        $required = $cycle === null ? 'required' : 'sometimes';

        return [
            'academic_year_id' => [$required, 'string', 'size:26'],
            'name' => [$required, 'string', 'max:150', Rule::unique('admission_cycles', 'name')->where('institution_id', app(CurrentInstitution::class)->id())->ignore($cycle?->id)],
            'opens_on' => [$required, 'date'],
            'closes_on' => [$required, 'date', 'after_or_equal:opens_on'],
            'application_fee' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'offer_valid_days' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'required_documents' => ['sometimes', 'array', 'max:20'],
            'required_documents.*' => ['string', 'max:50', 'regex:/^[a-z0-9_]+$/', 'distinct'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(array $validated, ?AdmissionCycle $cycle = null): array
    {
        if (isset($validated['academic_year_id'])) {
            $validated['academic_year_id'] = AcademicYear::query()->where('ulid', $validated['academic_year_id'])->firstOrFail()->id;
        }

        $opens = $validated['opens_on'] ?? $cycle?->opens_on;
        $closes = $validated['closes_on'] ?? $cycle?->closes_on;

        if ($opens !== null && $closes !== null && strtotime((string) $closes) < strtotime((string) $opens)) {
            throw new BusinessRuleException('The closing date must be on or after the opening date.', 'INVALID_DATES', 422);
        }

        return $validated;
    }

    private function assertNotClosed(AdmissionCycle $cycle): void
    {
        if ($cycle->status === CycleStatus::Closed) {
            throw new BusinessRuleException('This admission cycle is closed.', 'CYCLE_CLOSED', 409);
        }
    }
}
