<?php

namespace App\Modules\Admissions\Http\Controllers;

use App\Models\User;
use App\Modules\Admissions\Enums\CycleStatus;
use App\Modules\Admissions\Http\AdmissionPresenter;
use App\Modules\Admissions\Models\AdmissionCycle;
use App\Modules\Admissions\Models\AdmissionSeat;
use App\Modules\Admissions\Models\Application;
use App\Modules\Admissions\Services\AdmissionService;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Files\StoredFile;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The applicant's own applications.
 *
 * @tags Admissions: applicant
 */
class MyApplicationController extends Controller
{
    private const RELATIONS = ['cycle', 'seat.programme', 'user', 'documents.file', 'history.changer'];

    public function __construct(private AdmissionService $admissions) {}

    /**
     * Cycles taking applications now, with their programmes and quotas.
     */
    public function openCycles(): JsonResponse
    {
        $cycles = AdmissionCycle::query()
            ->with(['academicYear', 'seats.programme'])
            ->where('status', CycleStatus::Open)
            ->whereDate('opens_on', '<=', now())
            ->whereDate('closes_on', '>=', now())
            ->orderBy('closes_on')
            ->get();

        return ApiResponse::list($cycles, fn (AdmissionCycle $cycle): array => AdmissionPresenter::cycle($cycle, withSeats: true));
    }

    public function index(Request $request): JsonResponse
    {
        $applications = Application::query()
            ->with(['cycle', 'seat.programme', 'user'])
            ->where('user_id', $this->user($request)->id)
            ->latest('id')
            ->get();

        return ApiResponse::list($applications, fn (Application $application): array => AdmissionPresenter::application($application));
    }

    /**
     * Start an application (saved as a draft).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(array_merge(['seat_id' => ['required', 'string', 'size:26']], $this->formRules()));
        $seat = $this->seat($validated['seat_id']);

        $application = $this->admissions->start($this->user($request), $seat, $validated);

        return ApiResponse::data(AdmissionPresenter::application($application->load(self::RELATIONS), detail: true), 201);
    }

    public function show(Request $request, string $application): JsonResponse
    {
        return ApiResponse::data(AdmissionPresenter::application($this->own($request, $application)->load(self::RELATIONS), detail: true));
    }

    /**
     * Save the form while it is a draft or a correction was asked for.
     */
    public function update(Request $request, string $application): JsonResponse
    {
        $model = $this->own($request, $application);
        $validated = $request->validate(array_merge(['seat_id' => ['sometimes', 'string', 'size:26']], $this->formRules()));
        $seat = isset($validated['seat_id']) ? $this->seat($validated['seat_id']) : null;

        $this->admissions->updateForm($model, $validated, $seat);

        return $this->show($request, $application);
    }

    /**
     * Attach an uploaded file (POST /files with purpose "document") as one of the required documents.
     */
    public function attachDocument(Request $request, string $application): JsonResponse
    {
        $model = $this->own($request, $application);
        $validated = $request->validate([
            'document_type' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/'],
            'file_id' => ['required', 'string', 'size:26'],
        ]);

        $file = StoredFile::query()
            ->where('ulid', $validated['file_id'])
            ->where('uploaded_by', $this->user($request)->id)
            ->where('purpose', 'document')
            ->first();

        if ($file === null) {
            throw new BusinessRuleException('Upload the file with purpose "document" first.', 'DOCUMENT_FILE_INVALID', 422);
        }

        $this->admissions->attachDocument($model, $validated['document_type'], $file);

        return $this->show($request, $application);
    }

    public function submit(Request $request, string $application): JsonResponse
    {
        $this->admissions->submit($this->own($request, $application), $this->user($request));

        return $this->show($request, $application);
    }

    public function acceptOffer(Request $request, string $application): JsonResponse
    {
        $this->admissions->acceptOffer($this->own($request, $application), $this->user($request));

        return $this->show($request, $application);
    }

    /**
     * Withdraw the application (or decline an offer). Any held seat is released.
     */
    public function withdraw(Request $request, string $application): JsonResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $this->admissions->withdraw($this->own($request, $application), $this->user($request), $validated['reason'] ?? null);

        return $this->show($request, $application);
    }

    /**
     * @return array<string, mixed>
     */
    private function formRules(): array
    {
        return [
            'category' => ['sometimes', 'nullable', 'string', 'max:30'],
            'personal' => ['sometimes', 'array'],
            'personal.full_name' => ['sometimes', 'string', 'max:150'],
            'personal.date_of_birth' => ['sometimes', 'date', 'before:-14 years'],
            'personal.gender' => ['sometimes', 'in:female,male,other,prefer_not_to_say'],
            'personal.address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'personal.*' => ['nullable'],
            'guardian' => ['sometimes', 'array'],
            'guardian.name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'guardian.phone' => ['sometimes', 'nullable', 'regex:/^\+?[0-9]{10,15}$/'],
            'guardian.*' => ['nullable'],
            'academic' => ['sometimes', 'array'],
            'academic.percentage' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'academic.board' => ['sometimes', 'nullable', 'string', 'max:150'],
            'academic.year_of_passing' => ['sometimes', 'nullable', 'integer', 'min:1980', 'max:'.(now()->year + 1)],
            'academic.*' => ['nullable'],
            'entrance' => ['sometimes', 'array'],
            'entrance.exam' => ['sometimes', 'nullable', 'string', 'max:50'],
            'entrance.rank' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'entrance.allotment_no' => ['sometimes', 'nullable', 'string', 'max:50'],
            'entrance.*' => ['nullable'],
        ];
    }

    private function seat(string $ulid): AdmissionSeat
    {
        $seat = AdmissionSeat::query()->with('cycle')->where('ulid', $ulid)->first();

        if ($seat === null) {
            throw new BusinessRuleException('Choose a programme from an open admission cycle.', 'SEAT_INVALID', 422);
        }

        return $seat;
    }

    private function own(Request $request, string $ulid): Application
    {
        return Application::query()
            ->where('ulid', $ulid)
            ->where('user_id', $this->user($request)->id)
            ->firstOrFail();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
