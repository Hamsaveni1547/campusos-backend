<?php

namespace App\Modules\Exams\Http\Controllers;

use App\Models\User;
use App\Modules\Academics\Models\Course;
use App\Modules\Exams\Authorization\ExamPermissions;
use App\Modules\Exams\Models\ExamCycle;
use App\Modules\Exams\Models\ExamRegistration;
use App\Modules\Exams\Models\ExamResult;
use App\Modules\Exams\Models\ExamSchedule;
use App\Modules\Exams\Models\RevaluationRequest;
use App\Modules\Exams\Services\ExamService;
use App\Modules\Institution\Models\Term;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * @tags Examinations
 */
class ExamController extends Controller
{
    public function __construct(private ExamService $exams) {}

    /**
     * Cycle types and statuses, and the pass rules with grade bands.
     */
    public function options(): JsonResponse
    {
        $labels = fn (array $values): array => array_map(fn (string $value): array => ['value' => $value, 'label' => ucfirst(str_replace('_', ' ', $value))], $values);

        return ApiResponse::data(['types' => $labels(ExamCycle::TYPES), 'statuses' => $labels(ExamCycle::STATUSES), ...$this->exams->rules()]);
    }

    // ----- cycles ----------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny($request, [ExamPermissions::CyclesManage, ExamPermissions::ReportsView, ExamPermissions::EvaluationEnter, ExamPermissions::ResultsProcess, ExamPermissions::ResultsVerify, ExamPermissions::ResultsPublish]);

        $cycles = ExamCycle::query()->with('term')->withCount('registrations')->latest('id')->paginate(25);

        return ApiResponse::paginated($cycles, fn (ExamCycle $cycle): array => $this->cycle($cycle));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(ExamPermissions::CyclesManage);
        $cycle = ExamCycle::query()->create($this->validated($request));

        return ApiResponse::data($this->cycle($cycle->load('term')), 201);
    }

    public function show(Request $request, ExamCycle $cycle): JsonResponse
    {
        $this->authorizeAny($request, [ExamPermissions::CyclesManage, ExamPermissions::ReportsView, ExamPermissions::EvaluationEnter, ExamPermissions::ResultsProcess, ExamPermissions::ResultsVerify, ExamPermissions::ResultsPublish]);
        $user = $this->user($request);
        $cycle->load(['term', 'processor', 'verifier'])->loadCount('registrations');
        $counts = $cycle->registrations()->selectRaw('eligibility, count(*) as total')->groupBy('eligibility')->pluck('total', 'eligibility');

        return ApiResponse::data([
            ...$this->cycle($cycle),
            'processed_by' => $cycle->processor?->name,
            'processed_at' => $cycle->processed_at?->toIso8601String(),
            'verified_by' => $cycle->verifier?->name,
            'eligibility' => ['eligible' => (int) ($counts['eligible'] ?? 0), 'not_eligible' => (int) ($counts['not_eligible'] ?? 0), 'pending' => (int) ($counts['pending'] ?? 0)],
            'schedules' => $cycle->schedules()->with('course')->orderBy('exam_date')->orderBy('starts_at')->get()->map(fn (ExamSchedule $s): array => $this->schedule($s, $cycle))->values(),
            'can' => [
                'manage' => $user->can(ExamPermissions::CyclesManage),
                'process' => $cycle->isEvaluating() && $user->can(ExamPermissions::ResultsProcess),
                'verify' => $cycle->status === 'results_processed' && $cycle->processed_by !== $user->id && $user->can(ExamPermissions::ResultsVerify),
                'publish' => $cycle->status === 'results_verified' && $user->can(ExamPermissions::ResultsPublish),
                'enter_marks' => $cycle->isEvaluating() && $user->can(ExamPermissions::EvaluationEnter),
            ],
        ]);
    }

    public function update(Request $request, ExamCycle $cycle): JsonResponse
    {
        Gate::authorize(ExamPermissions::CyclesManage);
        $cycle->update($this->validated($request));

        return ApiResponse::data($this->cycle($cycle->load('term')));
    }

    /**
     * Move the cycle on: `open-registration`, `close-registration`, `eligibility` (recompute), `process`, `verify`, `publish`.
     */
    public function transition(Request $request, ExamCycle $cycle, string $action): JsonResponse
    {
        $user = $this->user($request);

        $steps = [
            'open-registration' => [ExamPermissions::CyclesManage, fn () => $this->exams->openRegistration($cycle)],
            'close-registration' => [ExamPermissions::CyclesManage, fn () => $this->exams->closeRegistration($cycle)],
            'eligibility' => [ExamPermissions::CyclesManage, fn () => $this->exams->computeEligibility($cycle)],
            'process' => [ExamPermissions::ResultsProcess, fn () => $this->exams->process($cycle, $user)],
            'verify' => [ExamPermissions::ResultsVerify, fn () => $this->exams->verify($cycle, $user)],
            'publish' => [ExamPermissions::ResultsPublish, fn () => $this->exams->publish($cycle, $user)],
        ];
        abort_unless(isset($steps[$action]), 404);
        [$permission, $run] = $steps[$action];
        Gate::authorize($permission);
        $run();

        return ApiResponse::data($this->cycle($cycle->refresh()->load('term')));
    }

    // ----- registrations ---------------------------------------------------

    /**
     * Registrations with eligibility and results. Filters: `filter[eligibility]`, `filter[course]` (course id), `filter[search]`.
     */
    public function registrations(Request $request, ExamCycle $cycle): JsonResponse
    {
        $this->authorizeAny($request, [ExamPermissions::CyclesManage, ExamPermissions::ReportsView, ExamPermissions::ResultsProcess, ExamPermissions::ResultsVerify]);
        $validated = $request->validate(['filter.eligibility' => ['nullable', Rule::in(['pending', 'eligible', 'not_eligible'])], 'filter.course' => ['nullable', 'string', 'size:26'], 'filter.search' => ['nullable', 'string', 'max:100']])['filter'] ?? [];
        $showCodes = $this->user($request)->can(ExamPermissions::ScriptsDecode);

        $rows = $cycle->registrations()
            ->with(['student.user', 'course'])
            ->when($validated['eligibility'] ?? null, fn ($query, string $value) => $query->where('eligibility', $value))
            ->when($validated['course'] ?? null, fn ($query, string $ulid) => $query->whereHas('course', fn ($q) => $q->where('ulid', $ulid)))
            ->when($validated['search'] ?? null, fn ($query, string $term) => $query->whereHas('student', fn ($q) => $q->where('register_no', 'like', "%{$term}%")->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$term}%"))))
            ->orderBy('student_id')
            ->paginate(50);

        return ApiResponse::paginated($rows, fn (ExamRegistration $row): array => [...$this->registration($row), 'script_code' => $showCodes ? $row->script_code : null]);
    }

    public function override(Request $request, ExamRegistration $registration): JsonResponse
    {
        Gate::authorize(ExamPermissions::EligibilityOverride);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->exams->override($registration, $validated['reason'], $this->user($request));

        return ApiResponse::message('Student made eligible.');
    }

    // ----- timetable -------------------------------------------------------

    public function storeSchedule(Request $request, ExamCycle $cycle): JsonResponse
    {
        Gate::authorize(ExamPermissions::CyclesManage);
        $schedule = $this->exams->saveSchedule($cycle, $this->validatedSchedule($request));

        return ApiResponse::data($this->schedule($schedule->load('course'), $cycle), 201);
    }

    public function updateSchedule(Request $request, ExamSchedule $schedule): JsonResponse
    {
        Gate::authorize(ExamPermissions::CyclesManage);
        $schedule = $this->exams->saveSchedule($schedule->cycle, $this->validatedSchedule($request), $schedule);

        return ApiResponse::data($this->schedule($schedule->load('course'), $schedule->cycle));
    }

    public function destroySchedule(ExamSchedule $schedule): JsonResponse
    {
        Gate::authorize(ExamPermissions::CyclesManage);
        abort_if(in_array($schedule->cycle->status, ['results_processed', 'results_verified', 'published'], true), 409, 'Results are already processed.');
        $schedule->delete();

        return ApiResponse::message('Exam removed from the timetable.');
    }

    // ----- marks -----------------------------------------------------------

    /**
     * Eligible answer scripts of one exam by code only, with marks entered so far.
     */
    public function scripts(ExamSchedule $schedule): JsonResponse
    {
        Gate::authorize(ExamPermissions::EvaluationEnter);
        $schedule->load(['course', 'cycle']);

        return ApiResponse::data([
            ...$this->schedule($schedule, $schedule->cycle),
            'editable' => $schedule->cycle->isEvaluating(),
            'scripts' => ExamRegistration::query()->where('exam_cycle_id', $schedule->exam_cycle_id)->where('course_id', $schedule->course_id)
                ->where('eligibility', 'eligible')->whereNotNull('script_code')->orderBy('script_code')->get()
                ->map(fn (ExamRegistration $r): array => ['code' => $r->script_code, 'attendance' => $r->attendance, 'marks' => $r->external_marks === null ? null : (float) $r->external_marks])->values(),
        ]);
    }

    /**
     * Save marks: `marks` = {script code: {attendance: present|absent, marks}}.
     */
    public function saveMarks(Request $request, ExamSchedule $schedule): JsonResponse
    {
        Gate::authorize(ExamPermissions::EvaluationEnter);
        $validated = $request->validate([
            'marks' => ['required', 'array', 'min:1'],
            'marks.*.attendance' => ['required', Rule::in(['present', 'absent'])],
            'marks.*.marks' => ['nullable', 'numeric', 'min:0'],
        ]);

        $entries = [];
        foreach ($validated['marks'] as $code => $entry) {
            $entries[(string) $code] = ['attendance' => $entry['attendance'], 'marks' => isset($entry['marks']) ? (float) $entry['marks'] : null];
        }

        $this->exams->enterMarks($schedule, $entries, $this->user($request));

        return ApiResponse::message('Marks saved.');
    }

    // ----- results ---------------------------------------------------------

    /**
     * Per-student results of a cycle with each course's grade, and pass-rate summary.
     */
    public function results(Request $request, ExamCycle $cycle): JsonResponse
    {
        $this->authorizeAny($request, [ExamPermissions::ReportsView, ExamPermissions::ResultsProcess, ExamPermissions::ResultsVerify, ExamPermissions::ResultsPublish]);
        $rows = $cycle->registrations()->with(['course', 'student.user'])->get();
        $results = $cycle->results()->with('student.user')->get()->sortBy(fn (ExamResult $r) => $r->student->register_no);
        $graded = $rows->whereNotNull('grade');

        return ApiResponse::data([
            'summary' => [
                'students' => $results->count(),
                'all_clear' => $results->where('backlogs', 0)->count(),
                'pass_percent' => $graded->isEmpty() ? null : round($graded->where('passed', true)->count() * 100 / $graded->count(), 2),
                'grades' => $graded->countBy('grade')->sortKeys()->all(),
            ],
            'courses' => $rows->groupBy('course_id')->map(fn ($courseRows): array => [
                'code' => $courseRows->first()->course->code,
                'title' => $courseRows->first()->course->title,
                'registered' => $courseRows->count(),
                'passed' => $courseRows->where('passed', true)->count(),
            ])->sortBy('code')->values(),
            'students' => $results->map(fn (ExamResult $r): array => [
                'id' => $r->student->ulid,
                'name' => $r->student->user->name,
                'register_no' => $r->student->register_no,
                'sgpa' => $r->sgpa === null ? null : (float) $r->sgpa,
                'cgpa' => $r->cgpa === null ? null : (float) $r->cgpa,
                'credits_earned' => (float) $r->credits_earned,
                'credits_registered' => (float) $r->credits_registered,
                'backlogs' => $r->backlogs,
                'grades' => $rows->where('student_id', $r->student_id)->map(fn (ExamRegistration $row): array => ['course' => $row->course->code, 'grade' => $row->grade])->values(),
            ])->values(),
        ]);
    }

    // ----- revaluation -----------------------------------------------------

    public function revaluations(Request $request): JsonResponse
    {
        Gate::authorize(ExamPermissions::RevaluationManage);
        $status = $request->validate(['filter.status' => ['nullable', Rule::in(['pending', 'completed', 'rejected'])]])['filter']['status'] ?? null;

        $requests = RevaluationRequest::query()
            ->with(['registration.course', 'registration.cycle', 'registration.student.user'])
            ->when($status, fn ($query, string $value) => $query->where('status', $value))
            ->latest('id')
            ->paginate(25);

        return ApiResponse::paginated($requests, fn (RevaluationRequest $r): array => [
            'id' => $r->ulid,
            'cycle' => $r->registration->cycle->name,
            'course' => ['code' => $r->registration->course->code, 'title' => $r->registration->course->title],
            'student' => ['name' => $r->registration->student->user->name, 'register_no' => $r->registration->student->register_no],
            'script_code' => $r->registration->script_code,
            'marks' => $r->registration->external_marks === null ? null : (float) $r->registration->external_marks,
            'grade' => $r->registration->grade,
            'reason' => $r->reason,
            'status' => $r->status,
            'old_marks' => $r->old_marks === null ? null : (float) $r->old_marks,
            'new_marks' => $r->new_marks === null ? null : (float) $r->new_marks,
            'remarks' => $r->remarks,
            'requested_at' => $r->created_at?->toIso8601String(),
        ]);
    }

    /**
     * Complete with `new_marks`, or reject with `remarks`.
     */
    public function decideRevaluation(Request $request, RevaluationRequest $revaluation): JsonResponse
    {
        Gate::authorize(ExamPermissions::RevaluationManage);
        $validated = $request->validate(['new_marks' => ['nullable', 'numeric', 'min:0'], 'remarks' => ['nullable', 'string', 'max:500']]);
        $this->exams->decideRevaluation($revaluation, isset($validated['new_marks']) ? (float) $validated['new_marks'] : null, $validated['remarks'] ?? null, $this->user($request));

        return ApiResponse::message('Revaluation decided.');
    }

    // ----- helpers ---------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function cycle(ExamCycle $cycle): array
    {
        return [
            'id' => $cycle->ulid,
            'name' => $cycle->name,
            'type' => $cycle->type,
            'status' => $cycle->status,
            'term' => ['id' => $cycle->term->ulid, 'name' => $cycle->term->name],
            'registration_ends_on' => $cycle->registration_ends_on?->toDateString(),
            'registrations' => $cycle->registrations_count ?? null,
            'published_at' => $cycle->published_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schedule(ExamSchedule $schedule, ExamCycle $cycle): array
    {
        return [
            'id' => $schedule->ulid,
            'course' => ['id' => $schedule->course->ulid, 'code' => $schedule->course->code, 'title' => $schedule->course->title],
            'exam_date' => $schedule->exam_date->toDateString(),
            'starts_at' => substr($schedule->starts_at, 0, 5),
            'ends_at' => substr($schedule->ends_at, 0, 5),
            'max_marks' => (float) $schedule->max_marks,
            'candidates' => ExamRegistration::query()->where('exam_cycle_id', $cycle->id)->where('course_id', $schedule->course_id)->where('eligibility', 'eligible')->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function registration(ExamRegistration $row): array
    {
        return [
            'id' => $row->ulid,
            'student' => ['id' => $row->student->ulid, 'name' => $row->student->user->name, 'register_no' => $row->student->register_no],
            'course' => ['id' => $row->course->ulid, 'code' => $row->course->code, 'title' => $row->course->title, 'credits' => (float) $row->course->credits],
            'kind' => $row->kind,
            'eligibility' => $row->eligibility,
            'reasons' => $row->eligibility_reasons ?? [],
            'override_reason' => $row->override_reason,
            'attendance' => $row->attendance,
            'internal' => $row->internal_marks === null ? null : (float) $row->internal_marks,
            'internal_out_of' => $row->internal_out_of === null ? null : (float) $row->internal_out_of,
            'external' => $row->external_marks === null ? null : (float) $row->external_marks,
            'total_percent' => $row->total_percent === null ? null : (float) $row->total_percent,
            'grade' => $row->grade,
            'grade_points' => $row->grade_points === null ? null : (float) $row->grade_points,
            'passed' => $row->passed,
            'revision' => $row->revision,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(ExamCycle::TYPES)],
            'term_id' => ['required', 'string', 'size:26'],
            'registration_ends_on' => ['nullable', 'date'],
        ]);
        $validated['term_id'] = Term::query()->where('ulid', $validated['term_id'])->firstOrFail()->id;

        return $validated;
    }

    /**
     * @return array{course_id: int, exam_date: string, starts_at: string, ends_at: string, max_marks: float}
     */
    private function validatedSchedule(Request $request): array
    {
        $validated = $request->validate([
            'course_id' => ['required', 'string', 'size:26'],
            'exam_date' => ['required', 'date'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
            'max_marks' => ['required', 'numeric', 'min:1', 'max:1000'],
        ]);

        return [
            'course_id' => Course::query()->where('ulid', $validated['course_id'])->firstOrFail()->id,
            'exam_date' => (string) $validated['exam_date'],
            'starts_at' => (string) $validated['starts_at'],
            'ends_at' => (string) $validated['ends_at'],
            'max_marks' => (float) $validated['max_marks'],
        ];
    }

    /**
     * @param  list<string>  $permissions
     */
    private function authorizeAny(Request $request, array $permissions): void
    {
        abort_unless($this->user($request)->canAny($permissions), 403);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
