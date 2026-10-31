<?php

namespace App\Modules\Admissions\Http\Controllers;

use App\Models\User;
use App\Modules\Admissions\Authorization\AdmissionsPermissions;
use App\Modules\Admissions\Enums\ApplicationStatus;
use App\Modules\Admissions\Http\AdmissionPresenter;
use App\Modules\Admissions\Models\Application;
use App\Modules\Admissions\Models\ApplicationDocument;
use App\Modules\Admissions\Services\AdmissionService;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * Admissions desk: verification, decisions, offers and confirmation.
 *
 * @tags Admissions: desk
 */
class ApplicationController extends Controller
{
    private const RELATIONS = ['cycle', 'seat.programme', 'user', 'documents.file', 'history.changer'];

    public function __construct(private AdmissionService $admissions) {}

    /**
     * Applications. Filters: `filter[status]` (comma list), `filter[cycle]`, `filter[programme]`, `filter[quota]`,
     * `filter[search]` (name, email, application number). Drafts are hidden unless asked for by status.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(AdmissionsPermissions::ApplicationsView);
        $request->validate(['filter.status' => ['sometimes', 'string'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        $statuses = array_filter(explode(',', (string) $request->input('filter.status')));

        $applications = Application::query()
            ->with(['cycle', 'seat.programme', 'user'])
            ->when($statuses !== [], fn ($query) => $query->whereIn('status', $statuses), fn ($query) => $query->where('status', '!=', ApplicationStatus::Draft))
            ->when($request->input('filter.cycle'), fn ($query, $id) => $query->whereHas('cycle', fn ($query) => $query->where('ulid', $id)))
            ->when($request->input('filter.programme'), fn ($query, $id) => $query->whereHas('seat.programme', fn ($query) => $query->where('ulid', $id)))
            ->when($request->input('filter.quota'), fn ($query, $quota) => $query->whereHas('seat', fn ($query) => $query->where('quota', $quota)))
            ->when($request->input('filter.search'), fn ($query, $search) => $query->where(fn ($query) => $query
                ->where('application_no', 'like', "%{$search}%")
                ->orWhereHas('user', fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))))
            ->orderByRaw('submitted_at is null, submitted_at asc')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return ApiResponse::paginated($applications, fn (Application $application): array => AdmissionPresenter::application($application));
    }

    public function show(Application $application): JsonResponse
    {
        Gate::authorize(AdmissionsPermissions::ApplicationsView);

        return ApiResponse::data(AdmissionPresenter::application($application->load(self::RELATIONS), detail: true));
    }

    /**
     * Verify or reject one document (reason needed to reject).
     */
    public function reviewDocument(Request $request, Application $application, string $document): JsonResponse
    {
        Gate::authorize(AdmissionsPermissions::ApplicationsVerify);
        $validated = $request->validate(['verified' => ['required', 'boolean'], 'remarks' => ['nullable', 'string', 'max:500']]);

        $model = ApplicationDocument::query()->where('application_id', $application->id)->where('ulid', $document)->firstOrFail();
        $this->admissions->reviewDocument($model, (bool) $validated['verified'], $validated['remarks'] ?? null, $this->user($request));

        return $this->show($application);
    }

    public function requestCorrection(Request $request, Application $application): JsonResponse
    {
        Gate::authorize(AdmissionsPermissions::ApplicationsVerify);
        $validated = $request->validate(['remarks' => ['required', 'string', 'max:1000']]);

        $this->admissions->requestCorrection($application, $validated['remarks'], $this->user($request));

        return $this->show($application);
    }

    /**
     * Mark verified once every document is verified; eligibility is then decided automatically.
     */
    public function completeVerification(Request $request, Application $application): JsonResponse
    {
        Gate::authorize(AdmissionsPermissions::ApplicationsVerify);
        $this->admissions->completeVerification($application, $this->user($request));

        return $this->show($application);
    }

    /**
     * Management / NRI quota approval by the institution head.
     */
    public function approve(Request $request, Application $application): JsonResponse
    {
        Gate::authorize(AdmissionsPermissions::ManagementApprove);
        $validated = $request->validate(['remarks' => ['nullable', 'string', 'max:1000']]);

        $this->admissions->approve($application, $this->user($request), $validated['remarks'] ?? null);

        return $this->show($application);
    }

    public function reject(Request $request, Application $application): JsonResponse
    {
        Gate::authorize(AdmissionsPermissions::ApplicationsProcess);
        $validated = $request->validate(['remarks' => ['required', 'string', 'max:1000']]);

        $this->admissions->reject($application, $validated['remarks'], $this->user($request));

        return $this->show($application);
    }

    /**
     * Hold a seat and offer admission (fails when the seat row is full).
     */
    public function offer(Request $request, Application $application): JsonResponse
    {
        Gate::authorize(AdmissionsPermissions::SeatsAllocate);
        $this->admissions->offer($application, $this->user($request));

        return $this->show($application);
    }

    /**
     * Record the admission fee and admit. Gives a register number and the Student role.
     */
    public function confirm(Request $request, Application $application): JsonResponse
    {
        Gate::authorize(AdmissionsPermissions::Confirm);
        $validated = $request->validate([
            'fee_amount' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'fee_reference' => ['required', 'string', 'max:100'],
        ]);

        $this->admissions->confirm($application, $this->user($request), (float) $validated['fee_amount'], $validated['fee_reference']);

        return $this->show($application);
    }

    /**
     * Counts per status for one cycle (dashboard tiles).
     */
    public function summary(Request $request): JsonResponse
    {
        Gate::authorize(AdmissionsPermissions::ApplicationsView);
        $request->validate(['filter.cycle' => ['required', 'string', 'size:26']]);

        $counts = Application::query()
            ->whereHas('cycle', fn ($query) => $query->where('ulid', $request->input('filter.cycle')))
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return ApiResponse::data(collect(ApplicationStatus::cases())->mapWithKeys(fn (ApplicationStatus $status): array => [$status->value => (int) ($counts[$status->value] ?? 0)]));
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
