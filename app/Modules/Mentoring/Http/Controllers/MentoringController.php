<?php

namespace App\Modules\Mentoring\Http\Controllers;

use App\Models\User;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Exams\Models\ExamResult;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\HR\Models\Employee;
use App\Modules\Institution\Models\Section;
use App\Modules\Institution\Models\Term;
use App\Modules\Mentoring\Authorization\MentoringPermissions as P;
use App\Modules\Mentoring\Models\MentorAssignment;
use App\Modules\Mentoring\Models\MentoringMeeting;
use App\Modules\Mentoring\Models\MentoringNote;
use App\Modules\Mentoring\Models\StudentConcern;
use App\Modules\Mentoring\Services\MentoringService;
use App\Modules\Students\Models\Student;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * @tags Mentoring
 */
class MentoringController extends Controller
{
    public function __construct(private MentoringService $mentoring) {}

    // ----- allocation ------------------------------------------------------

    /**
     * Current mentors by section: `filter[section]` (required), with each student's mentor.
     */
    public function assignments(Request $request): JsonResponse
    {
        abort_unless($this->user($request)->canAny([P::Allocate, P::ReportsView]), 403);
        $section = Section::query()->where('ulid', (string) $request->validate(['filter.section' => ['required', 'string', 'size:26']])['filter']['section'])->firstOrFail();
        $students = Student::query()->with('user')->where('section_id', $section->id)->whereNotIn('status', ['graduated', 'dropped_out', 'transferred_out'])->orderByRaw('roll_no is null, roll_no')->get();
        $current = MentorAssignment::query()->current()->with('mentor.user')->whereIn('student_id', $students->pluck('id'))->get()->keyBy('student_id');

        return ApiResponse::list($students, fn (Student $s): array => [...$this->student($s), 'mentor' => ($m = $current->get($s->id)) === null ? null : ['id' => $m->mentor->ulid, 'name' => $m->mentor->user->name, 'since' => $m->from_date->toDateString()]]);
    }

    /**
     * Share the students of sections who have no mentor among the chosen teachers: `section_ids[]`, `mentor_ids[]`.
     */
    public function allocate(Request $request): JsonResponse
    {
        Gate::authorize(P::Allocate);
        $validated = $request->validate(['section_ids' => ['required', 'array', 'min:1'], 'section_ids.*' => ['string', 'size:26'], 'mentor_ids' => ['required', 'array', 'min:1'], 'mentor_ids.*' => ['string', 'size:26']]);
        $count = $this->mentoring->allocate(
            Section::query()->whereIn('ulid', $validated['section_ids'])->pluck('id')->all(),
            Employee::query()->whereIn('ulid', $validated['mentor_ids'])->pluck('id')->all(),
        );

        return ApiResponse::data(['assigned' => $count, 'message' => $count === 0 ? 'Every student already has a mentor.' : "{$count} students assigned."]);
    }

    /**
     * Change a student's mentor: `mentor_id`, `reason?`.
     */
    public function reassign(Request $request, Student $student): JsonResponse
    {
        Gate::authorize(P::Allocate);
        $validated = $request->validate(['mentor_id' => ['required', 'string', 'size:26'], 'reason' => ['nullable', 'string', 'max:300']]);
        $this->mentoring->reassign($student, Employee::query()->where('ulid', $validated['mentor_id'])->firstOrFail(), $validated['reason'] ?? null);

        return ApiResponse::message('Mentor changed.');
    }

    // ----- mentor ----------------------------------------------------------

    /**
     * The signed-in mentor's current mentees with at-risk reasons.
     */
    public function mentees(Request $request): JsonResponse
    {
        $employee = Employee::query()->where('user_id', $this->user($request)->id)->first();
        $students = $employee === null ? collect() : Student::query()->with(['user', 'programme', 'section.batch'])
            ->whereIn('id', MentorAssignment::query()->current()->where('employee_id', $employee->id)->select('student_id'))
            ->orderBy('section_id')->orderByRaw('roll_no is null, roll_no')->get();

        return ApiResponse::list($students, $this->withFlags($students));
    }

    /**
     * Students with at least one at-risk reason. Filter: `filter[section]`.
     */
    public function atRisk(Request $request): JsonResponse
    {
        Gate::authorize(P::ReportsView);
        $section = $request->input('filter.section');
        $students = Student::query()->with(['user', 'programme', 'section.batch'])->where('status', 'active')
            ->when($section, fn ($q, $ulid) => $q->whereHas('section', fn ($s) => $s->where('ulid', $ulid)))
            ->orderBy('section_id')->orderByRaw('roll_no is null, roll_no')->limit(400)->get();
        $flags = $this->mentoring->flags($students);
        $mentors = MentorAssignment::query()->current()->with('mentor.user')->whereIn('student_id', $students->pluck('id'))->get()->keyBy('student_id');

        return ApiResponse::list($students->filter(fn (Student $s): bool => $flags[$s->id] !== [])->values(), fn (Student $s): array => [
            ...$this->student($s), 'reasons' => $flags[$s->id], 'mentor' => $mentors->get($s->id)?->mentor->user->name,
        ]);
    }

    /**
     * One mentee: profile, attendance, results, fees, notes, concerns and meetings.
     */
    public function overview(Request $request, Student $student, AttendanceService $attendance, FinanceService $finance): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($this->mentoring->canSee($user, $student), 403);
        $student->load(['user', 'programme', 'section.batch', 'guardians']);
        $term = Term::query()->where('is_current', true)->first();
        $result = ExamResult::query()->with('cycle')->where('student_id', $student->id)->whereHas('cycle', fn ($q) => $q->where('status', 'published'))->latest('exam_cycle_id')->first();
        $mentor = MentorAssignment::query()->current()->with('mentor.user')->where('student_id', $student->id)->first();

        return ApiResponse::data([
            ...$this->student($student),
            'email' => $student->user->email,
            'phone' => $student->user->mobile,
            'status' => $student->status->value,
            'guardians' => $student->guardians->map(fn ($g): array => ['relation' => $g->relation->value, 'name' => $g->name, 'phone' => $g->phone])->values(),
            'mentor' => $mentor === null ? null : ['name' => $mentor->mentor->user->name, 'since' => $mentor->from_date->toDateString()],
            'flags' => $this->mentoring->flags(collect([$student]))[$student->id],
            'attendance' => $term === null ? [] : array_map(fn (array $c): array => ['course' => $c['course'], 'percentage' => $c['percentage'], 'standing' => $c['standing']], $attendance->studentSummary($student, $term->id)),
            'result' => $result === null ? null : ['cycle' => $result->cycle->name, 'sgpa' => $result->sgpa === null ? null : (float) $result->sgpa, 'cgpa' => $result->cgpa === null ? null : (float) $result->cgpa, 'backlogs' => $result->backlogs],
            'fees' => $finance->totals($student),
            'notes' => MentoringNote::query()->with('author')->where('student_id', $student->id)
                ->where(fn ($q) => $q->where('visibility', 'shared')->orWhere('created_by', $user->id)->when($user->can(P::Confidential), fn ($w) => $w->orWhere('visibility', 'confidential')))
                ->latest('id')->get()->map(fn (MentoringNote $n): array => ['id' => $n->ulid, 'body' => $n->body, 'visibility' => $n->visibility, 'by' => $n->author?->name, 'at' => $n->created_at?->toIso8601String()])->values(),
            'concerns' => StudentConcern::query()->with('raiser')->where('student_id', $student->id)->orderByRaw("status = 'resolved'")->latest('id')->get()->map(fn (StudentConcern $c): array => $this->concern($c))->values(),
            'meetings' => $this->meetingsFor($student)->map(fn (MentoringMeeting $m): array => $this->meeting($m))->values(),
        ]);
    }

    /**
     * Add a note: `body`, `visibility` (shared|confidential).
     */
    public function addNote(Request $request, Student $student): JsonResponse
    {
        abort_unless($this->mentoring->canSee($this->user($request), $student), 403);
        $validated = $request->validate(['body' => ['required', 'string', 'max:5000'], 'visibility' => ['required', Rule::in(['shared', 'confidential'])]]);
        $note = new MentoringNote(['student_id' => $student->id, 'visibility' => $validated['visibility']]);
        $note->forceFill(['body' => $validated['body']])->save();

        return ApiResponse::data(['id' => $note->ulid], 201);
    }

    /**
     * Raise a concern: `category`, `description`, `action_plan?`, `review_on?`.
     */
    public function addConcern(Request $request, Student $student): JsonResponse
    {
        abort_unless($this->mentoring->canSee($this->user($request), $student), 403);
        $validated = $request->validate(['category' => ['required', Rule::in(StudentConcern::CATEGORIES)], 'description' => ['required', 'string', 'max:2000'], 'action_plan' => ['nullable', 'string', 'max:2000'], 'review_on' => ['nullable', 'date']]);
        $concern = StudentConcern::query()->create([...$validated, 'student_id' => $student->id]);
        $concern->forceFill(['status' => 'open'])->save();

        return ApiResponse::data(['id' => $concern->ulid], 201);
    }

    /**
     * Update the plan or resolve: `action_plan?`, `review_on?`, `resolve?`, `outcome?`.
     */
    public function updateConcern(Request $request, StudentConcern $concern): JsonResponse
    {
        abort_unless($this->mentoring->canSee($this->user($request), $concern->student), 403);
        $validated = $request->validate(['action_plan' => ['nullable', 'string', 'max:2000'], 'review_on' => ['nullable', 'date'], 'resolve' => ['sometimes', 'boolean'], 'outcome' => ['nullable', 'required_if:resolve,true', 'string', 'max:2000']]);

        if ($concern->status === 'resolved') {
            throw new BusinessRuleException('This concern is already resolved.', 'INVALID_STATUS', 409);
        }

        $concern->fill(collect($validated)->only(['action_plan', 'review_on'])->all());

        if ($validated['resolve'] ?? false) {
            $concern->forceFill(['status' => 'resolved', 'outcome' => $validated['outcome'], 'resolved_at' => now()]);
        }

        $concern->save();

        return ApiResponse::data($this->concern($concern->load('raiser')));
    }

    // ----- meetings --------------------------------------------------------

    /**
     * The mentor's meetings, upcoming and requested first.
     */
    public function meetings(Request $request): JsonResponse
    {
        $employee = $this->mentorEmployee($request);
        $meetings = MentoringMeeting::query()->where('employee_id', $employee->id)
            ->orderByRaw("case status when 'requested' then 0 when 'scheduled' then 1 else 2 end")->orderByDesc('scheduled_at')->limit(100)->get();

        return ApiResponse::list($meetings, fn (MentoringMeeting $m): array => $this->meeting($m));
    }

    /**
     * Schedule a meeting with mentees: `student_ids[]`, `scheduled_at`, `agenda`.
     */
    public function schedule(Request $request): JsonResponse
    {
        $employee = $this->mentorEmployee($request);
        $validated = $request->validate(['student_ids' => ['required', 'array', 'min:1'], 'student_ids.*' => ['string', 'size:26'], 'scheduled_at' => ['required', 'date', 'after:now'], 'agenda' => ['required', 'string', 'max:500']]);
        $ids = $this->ownMentees($employee, $validated['student_ids']);
        $meeting = MentoringMeeting::query()->create(['employee_id' => $employee->id, 'student_ids' => $ids, 'scheduled_at' => $validated['scheduled_at'], 'agenda' => $validated['agenda']]);
        $meeting->forceFill(['status' => 'scheduled'])->save();

        return ApiResponse::data(['id' => $meeting->ulid], 201);
    }

    /**
     * Schedule a requested meeting (`scheduled_at`), record it (`status` held, `minutes`, `attended_ids[]`) or cancel it.
     */
    public function updateMeeting(Request $request, MentoringMeeting $meeting): JsonResponse
    {
        abort_unless($meeting->employee_id === $this->mentorEmployee($request)->id, 403);
        $validated = $request->validate(['status' => ['required', Rule::in(['scheduled', 'held', 'cancelled'])], 'scheduled_at' => ['nullable', 'required_if:status,scheduled', 'date'], 'minutes' => ['nullable', 'required_if:status,held', 'string', 'max:5000'], 'attended_ids' => ['nullable', 'array'], 'attended_ids.*' => ['string', 'size:26']]);

        if (in_array($meeting->status, ['held', 'cancelled'], true)) {
            throw new BusinessRuleException('This meeting is already '.$meeting->status.'.', 'INVALID_STATUS', 409);
        }

        $meeting->forceFill([
            'status' => $validated['status'],
            'scheduled_at' => $validated['scheduled_at'] ?? $meeting->scheduled_at,
            'minutes' => $validated['minutes'] ?? $meeting->minutes,
            'attended_ids' => $validated['status'] === 'held' ? Student::query()->whereIn('ulid', $validated['attended_ids'] ?? [])->whereIn('id', $meeting->student_ids)->pluck('id')->all() : $meeting->attended_ids,
        ])->save();

        return ApiResponse::data($this->meeting($meeting));
    }

    // ----- student ---------------------------------------------------------

    /**
     * The signed-in student's mentor and meetings.
     */
    public function myMentor(Request $request): JsonResponse
    {
        $student = $this->ownStudent($request);
        $assignment = MentorAssignment::query()->current()->with(['mentor.user', 'mentor.department', 'mentor.designation'])->where('student_id', $student->id)->first();

        return ApiResponse::data([
            'mentor' => $assignment === null ? null : [
                'name' => $assignment->mentor->user->name, 'email' => $assignment->mentor->user->email,
                'designation' => $assignment->mentor->designation?->name, 'department' => $assignment->mentor->department?->name,
                'since' => $assignment->from_date->toDateString(),
            ],
            'meetings' => $this->meetingsFor($student)->map(fn (MentoringMeeting $m): array => [...$this->meeting($m), 'minutes' => null])->values(),
        ]);
    }

    /**
     * Ask the mentor for a meeting: `agenda`.
     */
    public function requestMeeting(Request $request): JsonResponse
    {
        $student = $this->ownStudent($request);
        $agenda = (string) $request->validate(['agenda' => ['required', 'string', 'max:500']])['agenda'];
        $assignment = MentorAssignment::query()->current()->where('student_id', $student->id)->first() ?? throw new BusinessRuleException('You do not have a mentor yet.', 'NO_MENTOR', 409);

        if (MentoringMeeting::query()->where('status', 'requested')->whereJsonContains('student_ids', $student->id)->exists()) {
            throw new BusinessRuleException('You already have a request waiting.', 'REQUEST_PENDING', 409);
        }

        $meeting = MentoringMeeting::query()->create(['employee_id' => $assignment->employee_id, 'student_ids' => [$student->id], 'agenda' => $agenda]);
        $meeting->forceFill(['status' => 'requested'])->save();

        return ApiResponse::data(['id' => $meeting->ulid], 201);
    }

    // ----- helpers ---------------------------------------------------------

    /**
     * @param  Collection<int, Student>  $students
     * @return callable(Student): array<string, mixed>
     */
    private function withFlags(Collection $students): callable
    {
        $flags = $this->mentoring->flags($students);

        return fn (Student $s): array => [...$this->student($s), 'reasons' => $flags[$s->id] ?? []];
    }

    /**
     * @return Collection<int, MentoringMeeting>
     */
    private function meetingsFor(Student $student): Collection
    {
        return MentoringMeeting::query()->whereJsonContains('student_ids', $student->id)->orderByDesc('scheduled_at')->limit(30)->get();
    }

    /**
     * @param  list<string>  $ulids
     * @return list<int>
     */
    private function ownMentees(Employee $employee, array $ulids): array
    {
        $ids = Student::query()->whereIn('ulid', $ulids)->whereIn('id', MentorAssignment::query()->current()->where('employee_id', $employee->id)->select('student_id'))->pluck('id')->all();

        if (count($ids) !== count($ulids)) {
            throw new BusinessRuleException('Meetings are only for your own mentees.', 'NOT_MENTEE', 422);
        }

        return $ids;
    }

    private function mentorEmployee(Request $request): Employee
    {
        Gate::authorize(P::Mentor);

        return Employee::query()->where('user_id', $this->user($request)->id)->first() ?? throw new BusinessRuleException('You do not have a staff record in this institution.', 'NOT_AN_EMPLOYEE', 404);
    }

    private function ownStudent(Request $request): Student
    {
        return Student::query()->where('user_id', $this->user($request)->id)->first() ?? throw new BusinessRuleException('You do not have a student record in this institution.', 'NOT_A_STUDENT', 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function student(Student $s): array
    {
        return [
            'id' => $s->ulid, 'name' => $s->user->name, 'register_no' => $s->register_no, 'roll_no' => $s->roll_no,
            'programme' => $s->relationLoaded('programme') ? $s->programme->code : null,
            'section' => $s->relationLoaded('section') && $s->section !== null ? $s->section->batch->name.' '.$s->section->name : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function concern(StudentConcern $c): array
    {
        return [
            'id' => $c->ulid, 'category' => $c->category, 'description' => $c->description, 'action_plan' => $c->action_plan,
            'review_on' => $c->review_on?->toDateString(), 'status' => $c->status, 'outcome' => $c->outcome,
            'raised_by' => $c->raiser?->name, 'raised_at' => $c->created_at?->toIso8601String(), 'resolved_at' => $c->resolved_at?->toIso8601String(),
            'overdue' => $c->status === 'open' && $c->review_on !== null && $c->review_on->lt(today()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function meeting(MentoringMeeting $m): array
    {
        $students = Student::query()->with('user')->whereIn('id', $m->student_ids)->get();

        return [
            'id' => $m->ulid, 'status' => $m->status, 'scheduled_at' => $m->scheduled_at?->toIso8601String(), 'agenda' => $m->agenda, 'minutes' => $m->minutes,
            'students' => $students->map(fn (Student $s): array => ['id' => $s->ulid, 'name' => $s->user->name, 'attended' => in_array($s->id, $m->attended_ids ?? [], true)])->values()->all(),
            'past' => $m->scheduled_at !== null && Carbon::parse($m->scheduled_at)->isPast(),
        ];
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
