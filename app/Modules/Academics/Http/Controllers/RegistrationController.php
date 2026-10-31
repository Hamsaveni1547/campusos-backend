<?php

namespace App\Modules\Academics\Http\Controllers;

use App\Models\User;
use App\Modules\Academics\Authorization\AcademicsPermissions;
use App\Modules\Academics\Http\AcademicsPresenter;
use App\Modules\Academics\Models\CourseOffering;
use App\Modules\Academics\Models\CourseRegistration;
use App\Modules\Academics\Models\OfferingSection;
use App\Modules\Academics\Models\TermRegistration;
use App\Modules\Academics\Services\AcademicsService;
use App\Modules\Academics\Services\RegistrationService;
use App\Modules\Institution\Models\Term;
use App\Modules\Students\Models\Student;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Http\ApiResponse;
use App\Shared\Settings\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * @tags Academics: term registration
 */
class RegistrationController extends Controller
{
    private const RELATIONS = ['student.user', 'student.programme', 'term', 'section.batch', 'courses.offering.course', 'decider'];

    public function __construct(private RegistrationService $registrations) {}

    /**
     * The signed-in student's registration for the current term, with the courses they can take.
     */
    public function mine(Request $request, SettingsRepository $settings): JsonResponse
    {
        $student = $this->student($request);
        $term = $this->currentTerm();
        $registration = $term ? TermRegistration::query()->with(self::RELATIONS)->where('student_id', $student->id)->where('term_id', $term->id)->first() : null;
        $available = $term ? $this->registrations->available($student, $term) : null;

        return ApiResponse::data([
            'term' => $term === null ? null : ['id' => $term->ulid, 'name' => $term->name],
            'credit_limit' => (float) $settings->get('academics', 'max_credits_per_term'),
            'registration' => $registration === null ? null : $this->present($registration),
            'can_register' => $term !== null && $student->section_id !== null && ($registration === null || $registration->status->value === 'rejected'),
            'core' => $available === null ? [] : $available['core']->map(fn (CourseOffering $offering): array => ['offering_id' => $offering->ulid, 'course' => AcademicsPresenter::course($offering->course->loadMissing('department'))])->values(),
            'electives' => $available === null ? [] : $available['electives']->map(fn (CourseOffering $offering): array => [
                'offering_id' => $offering->ulid,
                'course' => AcademicsPresenter::course($offering->course->loadMissing('department')),
                'capacity' => $offering->capacity,
                'seats_left' => $offering->capacity === null ? null : max(0, $offering->capacity - $this->registrations->taken($offering)),
            ])->values(),
        ]);
    }

    /**
     * Register for the current term: `elective_offering_ids[]`. Core courses are added automatically.
     */
    public function register(Request $request, SettingsRepository $settings): JsonResponse
    {
        $validated = $request->validate(['elective_offering_ids' => ['present', 'array', 'max:10'], 'elective_offering_ids.*' => ['string', 'size:26', 'distinct']]);
        $term = $this->currentTerm() ?? throw new BusinessRuleException('There is no current term.', 'TERM_NOT_OPEN', 409);
        $ids = CourseOffering::query()->whereIn('ulid', $validated['elective_offering_ids'])->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if (count($ids) !== count($validated['elective_offering_ids'])) {
            throw new BusinessRuleException('One of the electives was not found.', 'ELECTIVE_INVALID', 422);
        }

        $this->registrations->register($this->student($request), $term, $ids);

        return $this->mine($request, $settings);
    }

    /**
     * Registrations. Filters: `filter[term]` (default current), `filter[status]`, `filter[programme]`, `filter[section]`, `filter[search]`.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(AcademicsPermissions::RegistrationsApprove);
        $term = $request->input('filter.term') ? Term::query()->where('ulid', $request->input('filter.term'))->first() : $this->currentTerm();

        $registrations = TermRegistration::query()
            ->with(self::RELATIONS)
            ->where('term_id', $term->id ?? 0)
            ->when($request->input('filter.status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->input('filter.programme'), fn ($query, $id) => $query->whereHas('student.programme', fn ($query) => $query->where('ulid', $id)))
            ->when($request->input('filter.section'), fn ($query, $id) => $query->whereHas('section', fn ($query) => $query->where('ulid', $id)))
            ->when($request->input('filter.search'), fn ($query, $search) => $query->whereHas('student', fn ($query) => $query->where('register_no', 'like', "%{$search}%")->orWhereHas('user', fn ($query) => $query->where('name', 'like', "%{$search}%"))))
            ->latest('submitted_at')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return ApiResponse::paginated($registrations, fn (TermRegistration $registration): array => $this->present($registration));
    }

    /**
     * Approve or reject one or more: `ids[]`, `approve`, `remarks` (required to reject).
     */
    public function decide(Request $request): JsonResponse
    {
        Gate::authorize(AcademicsPermissions::RegistrationsApprove);
        $validated = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['string', 'size:26'], 'approve' => ['required', 'boolean'], 'remarks' => ['nullable', 'string', 'max:1000']]);

        /** @var User $staff */
        $staff = $request->user();
        $done = 0;

        TermRegistration::query()->whereIn('ulid', $validated['ids'])->each(function (TermRegistration $registration) use ($validated, $staff, &$done): void {
            $this->registrations->decide($registration, (bool) $validated['approve'], $validated['remarks'] ?? null, $staff);
            $done++;
        });

        return ApiResponse::data(['decided' => $done, 'message' => "{$done} registration(s) ".($validated['approve'] ? 'approved' : 'rejected').'.']);
    }

    /**
     * Students registered for a class (approved and submitted). Teachers of the class and staff who can view.
     */
    public function roster(Request $request, string $offeringSection, AcademicsService $academics): JsonResponse
    {
        $class = OfferingSection::query()->with('offering.course')->where('ulid', $offeringSection)->firstOrFail();

        /** @var User $user */
        $user = $request->user();

        if (! $academics->teaches($user, $class) && ! $user->can(AcademicsPermissions::View)) {
            abort(403);
        }

        $students = CourseRegistration::query()
            ->with('registration.student.user')
            ->where('course_offering_id', $class->course_offering_id)
            ->whereHas('registration', fn ($query) => $query->where('section_id', $class->section_id)->where('status', '!=', 'rejected'))
            ->get()
            ->map(fn (CourseRegistration $entry): array => [
                'student_id' => $entry->registration->student->ulid,
                'register_no' => $entry->registration->student->register_no,
                'roll_no' => $entry->registration->student->roll_no,
                'name' => $entry->registration->student->user->name,
                'registration_status' => $entry->registration->status->value,
            ])
            ->sortBy(fn (array $row): string => ($row['roll_no'] ?? '~').$row['register_no'])
            ->values();

        return ApiResponse::data(['class' => ['id' => $class->ulid, 'course' => $class->offering->course->code], 'students' => $students]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TermRegistration $registration): array
    {
        return [
            'id' => $registration->ulid,
            'student' => ['id' => $registration->student->ulid, 'name' => $registration->student->user->name, 'register_no' => $registration->student->register_no, 'programme' => $registration->student->programme->name],
            'term' => ['id' => $registration->term->ulid, 'name' => $registration->term->name],
            'section' => ['name' => $registration->section->name, 'batch' => $registration->section->batch->name],
            'term_number' => $registration->term_number,
            'status' => $registration->status->value,
            'total_credits' => (float) $registration->total_credits,
            'remarks' => $registration->remarks,
            'submitted_at' => $registration->submitted_at?->toIso8601String(),
            'decided_by' => $registration->decider?->name,
            'decided_at' => $registration->decided_at?->toIso8601String(),
            'courses' => $registration->courses->map(fn (CourseRegistration $entry): array => [
                'offering_id' => $entry->offering->ulid,
                'code' => $entry->offering->course->code,
                'title' => $entry->offering->course->title,
                'credits' => (float) $entry->offering->course->credits,
                'type' => $entry->type,
            ])->values()->all(),
        ];
    }

    private function student(Request $request): Student
    {
        /** @var User $user */
        $user = $request->user();

        return Student::query()->where('user_id', $user->id)->first() ?? throw new BusinessRuleException('You do not have a student record in this institution.', 'NOT_A_STUDENT', 404);
    }

    private function currentTerm(): ?Term
    {
        return Term::query()->where('is_current', true)->first();
    }
}
