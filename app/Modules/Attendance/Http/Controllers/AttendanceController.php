<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Models\User;
use App\Modules\Academics\Models\OfferingSection;
use App\Modules\Academics\Services\AcademicsService;
use App\Modules\Attendance\Authorization\AttendancePermissions;
use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\AttendanceCorrection;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Models\AttendanceSession;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\HR\Models\Employee;
use App\Modules\Institution\Models\Section;
use App\Modules\Institution\Models\Term;
use App\Modules\Students\Models\Student;
use App\Modules\Timetable\Models\TimeSlot;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Http\ApiResponse;
use App\Shared\Settings\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * @tags Attendance
 */
class AttendanceController extends Controller
{
    public function __construct(private AttendanceService $attendance) {}

    /**
     * Statuses and the institution's attendance rules.
     */
    public function options(SettingsRepository $settings): JsonResponse
    {
        return ApiResponse::data([
            'statuses' => collect(AttendanceStatus::cases())->map(fn (AttendanceStatus $status): array => ['value' => $status->value, 'label' => $status->label()])->values(),
            'counts_as_attended' => $this->attendance->attendedStatuses(),
            'threshold' => (float) $settings->get('attendance', 'threshold'),
            'condonation_min' => (float) $settings->get('attendance', 'condonation_min'),
            'edit_window_hours' => (int) $settings->get('attendance', 'edit_window_hours'),
        ]);
    }

    /**
     * Periods in the signed-in teacher's last 7 days that still need attendance.
     */
    public function pending(Request $request): JsonResponse
    {
        $employee = Employee::query()->where('user_id', $this->user($request)->id)->first();

        return ApiResponse::data($employee === null ? [] : $this->attendance->pending($employee));
    }

    /**
     * Attendance sheet for a class on a date: `class` (class id), `date`, `slot?` (period id).
     */
    public function sheet(Request $request): JsonResponse
    {
        $validated = $request->validate(['class' => ['required', 'string', 'size:26'], 'date' => ['required', 'date'], 'slot' => ['nullable', 'string', 'size:26']]);
        $class = $this->class($validated['class']);
        $date = Carbon::parse($validated['date']);
        $slot = isset($validated['slot']) ? TimeSlot::query()->where('ulid', $validated['slot'])->firstOrFail() : null;
        $user = $this->user($request);
        $canMark = $this->attendance->canMark($user, $class, $date);

        if (! $canMark && ! $user->can(AttendancePermissions::ReportsView)) {
            abort(403);
        }

        $session = AttendanceSession::query()->with('marker')->where('offering_section_id', $class->id)->whereDate('date', $date)->where('time_slot_id', $slot?->id)->first();
        $marks = $session === null ? collect() : $session->records()->pluck('status', 'student_id');

        return ApiResponse::data([
            'class' => ['id' => $class->ulid, 'course' => ['code' => $class->offering->course->code, 'title' => $class->offering->course->title], 'section' => $class->section->batch->name.' '.$class->section->name],
            'date' => $date->toDateString(),
            'slot' => $slot === null ? null : ['id' => $slot->ulid, 'label' => $slot->label, 'starts_at' => substr($slot->starts_at, 0, 5)],
            'session' => $session === null ? null : ['id' => $session->ulid, 'marked_by' => $session->marker?->name, 'marked_at' => $session->marked_at?->toIso8601String(), 'notes' => $session->notes, 'open' => $this->attendance->isOpen($session)],
            'can_mark' => $canMark && ($session === null || $this->attendance->isOpen($session) || $user->can(AttendancePermissions::Edit)),
            'students' => $this->attendance->roster($class)->map(fn (Student $student): array => [
                'student_id' => $student->ulid,
                'register_no' => $student->register_no,
                'roll_no' => $student->roll_no,
                'name' => $student->user->name,
                'status' => $marks->has($student->id) ? $marks[$student->id]->value : null,
            ])->values(),
        ]);
    }

    /**
     * Save a sheet: `class_id, date, time_slot_id?, component?, notes?, statuses{student_id: status}` (others present).
     */
    public function mark(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'class_id' => ['required', 'string', 'size:26'],
            'date' => ['required', 'date'],
            'time_slot_id' => ['nullable', 'string', 'size:26'],
            'component' => ['sometimes', 'in:theory,lab,tutorial'],
            'notes' => ['nullable', 'string', 'max:500'],
            'statuses' => ['present', 'array'],
            'statuses.*' => [Rule::enum(AttendanceStatus::class)],
        ]);

        $class = $this->class($validated['class_id']);
        $students = Student::query()->whereIn('ulid', array_keys($validated['statuses']))->pluck('id', 'ulid');

        if ($students->count() !== count($validated['statuses'])) {
            throw new BusinessRuleException('Some students are not in this class.', 'STUDENT_NOT_IN_CLASS', 422);
        }

        $statuses = collect($validated['statuses'])->mapWithKeys(fn (string $status, string $ulid): array => [(int) $students[$ulid] => $status])->all();
        $slot = isset($validated['time_slot_id']) ? TimeSlot::query()->where('ulid', $validated['time_slot_id'])->firstOrFail() : null;

        $session = $this->attendance->mark($class, Carbon::parse($validated['date']), $slot, $statuses, $validated['notes'] ?? null, $this->user($request), $validated['component'] ?? 'theory');
        $absent = $session->records()->where('status', AttendanceStatus::Absent)->count();

        return ApiResponse::data(['id' => $session->ulid, 'message' => 'Attendance saved. '.$absent.' absent.']);
    }

    /**
     * Percentages for every student of a class. The class's teachers and people with report access.
     */
    public function classSummary(Request $request, string $class, AcademicsService $academics): JsonResponse
    {
        $model = $this->class($class);
        $user = $this->user($request);

        if (! $user->can(AttendancePermissions::ReportsView) && ! $academics->teaches($user, $model)) {
            abort(403);
        }

        return ApiResponse::data([
            'class' => ['id' => $model->ulid, 'course' => ['code' => $model->offering->course->code, 'title' => $model->offering->course->title], 'section' => $model->section->batch->name.' '.$model->section->name],
            'students' => $this->attendance->classSummary($model),
        ]);
    }

    /**
     * Shortage list for a section: `filter[section]`, `filter[term]` (default current), `filter[standing]` (shortage|condonable, default both).
     */
    public function shortage(Request $request): JsonResponse
    {
        Gate::authorize(AttendancePermissions::ReportsView);
        $request->validate(['filter.section' => ['required', 'string', 'size:26']]);
        $section = Section::query()->where('ulid', $request->input('filter.section'))->firstOrFail();
        $term = $this->term($request->input('filter.term'));
        $standings = array_filter(explode(',', (string) $request->input('filter.standing', 'shortage,condonable')));

        $rows = OfferingSection::query()
            ->with(['offering.course', 'section.batch'])
            ->where('section_id', $section->id)
            ->whereHas('offering', fn ($query) => $query->where('term_id', $term->id ?? 0))
            ->get()
            ->flatMap(fn (OfferingSection $class) => collect($this->attendance->classSummary($class))
                ->filter(fn (array $row): bool => in_array($row['standing'], $standings, true))
                ->map(fn (array $row): array => $row + ['class_id' => $class->ulid, 'course' => ['code' => $class->offering->course->code, 'title' => $class->offering->course->title]]))
            ->sortBy(fn (array $row): string => ($row['roll_no'] ?? '~').$row['register_no'].$row['course']['code'])
            ->values();

        return ApiResponse::data(['section' => $section->batch->name.' '.$section->name, 'rows' => $rows]);
    }

    /**
     * Condone a shortage within the band: `student_id, class_id, reason, fee_reference?`.
     */
    public function condone(Request $request): JsonResponse
    {
        Gate::authorize(AttendancePermissions::CondonationManage);
        $validated = $request->validate(['student_id' => ['required', 'string', 'size:26'], 'class_id' => ['required', 'string', 'size:26'], 'reason' => ['required', 'string', 'max:500'], 'fee_reference' => ['nullable', 'string', 'max:100']]);

        $student = Student::query()->where('ulid', $validated['student_id'])->firstOrFail();
        $condonation = $this->attendance->condone($student, $this->class($validated['class_id']), $validated['reason'], $validated['fee_reference'] ?? null, $this->user($request));

        return ApiResponse::data(['id' => $condonation->ulid, 'message' => 'Shortage condoned.'], 201);
    }

    /**
     * The signed-in student's attendance per course this term.
     */
    public function mine(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $term = $this->term(null);

        return ApiResponse::data(['term' => $term === null ? null : ['id' => $term->ulid, 'name' => $term->name], 'courses' => $term === null ? [] : $this->attendance->studentSummary($student, $term->id)]);
    }

    /**
     * The signed-in student's marks in one class, newest first, with any correction requests.
     */
    public function mineForClass(Request $request, string $class): JsonResponse
    {
        $student = $this->student($request);
        $model = $this->class($class);

        $records = AttendanceRecord::query()
            ->with('session.slot')
            ->where('student_id', $student->id)
            ->whereHas('session', fn ($query) => $query->where('offering_section_id', $model->id))
            ->get()
            ->sortByDesc(fn (AttendanceRecord $record): string => $record->session->date->toDateString().$record->session->starts_at);
        $corrections = AttendanceCorrection::query()->whereIn('attendance_record_id', $records->pluck('id'))->latest('id')->get()->groupBy('attendance_record_id');

        return ApiResponse::data([
            'class' => ['id' => $model->ulid, 'course' => ['code' => $model->offering->course->code, 'title' => $model->offering->course->title]],
            'records' => $records->map(fn (AttendanceRecord $record): array => [
                'session_id' => $record->session->ulid,
                'date' => $record->session->date->toDateString(),
                'slot' => $record->session->slot?->label,
                'status' => $record->status->value,
                'correction' => ($c = $corrections->get($record->id)?->first()) === null ? null : ['status' => $c->status, 'requested_status' => $c->requested_status->value, 'remarks' => $c->remarks],
            ])->values(),
        ]);
    }

    /**
     * Ask for a mark to be changed: `session_id, requested_status, reason`, and `student_id` when a teacher asks.
     */
    public function requestCorrection(Request $request, AcademicsService $academics): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'string', 'size:26'],
            'student_id' => ['nullable', 'string', 'size:26'],
            'requested_status' => ['required', Rule::enum(AttendanceStatus::class)],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $user = $this->user($request);
        $session = AttendanceSession::query()->with('class')->where('ulid', $validated['session_id'])->firstOrFail();

        if (isset($validated['student_id'])) {
            if (! $academics->teaches($user, $session->class)) {
                abort(403);
            }

            $studentId = Student::query()->where('ulid', $validated['student_id'])->value('id');
        } else {
            $studentId = $this->student($request)->id;
        }

        $record = AttendanceRecord::query()->where('attendance_session_id', $session->id)->where('student_id', $studentId)->firstOrFail();
        $correction = $this->attendance->requestCorrection($record, AttendanceStatus::from($validated['requested_status']), $validated['reason'], $user);

        return ApiResponse::data(['id' => $correction->ulid, 'message' => 'Correction requested.'], 201);
    }

    /**
     * Correction requests. Filter: `filter[status]` (default pending).
     */
    public function corrections(Request $request): JsonResponse
    {
        Gate::authorize(AttendancePermissions::CorrectionsApprove);

        $corrections = AttendanceCorrection::query()
            ->with(['record.student.user', 'record.session.class.offering.course', 'record.session.class.section.batch', 'record.session.slot', 'requester', 'reviewer'])
            ->where('status', $request->input('filter.status', 'pending'))
            ->latest('id')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return ApiResponse::paginated($corrections, fn (AttendanceCorrection $correction): array => [
            'id' => $correction->ulid,
            'student' => ['id' => $correction->record->student->ulid, 'name' => $correction->record->student->user->name, 'register_no' => $correction->record->student->register_no],
            'course' => $correction->record->session->class->offering->course->code,
            'section' => $correction->record->session->class->section->batch->name.' '.$correction->record->session->class->section->name,
            'date' => $correction->record->session->date->toDateString(),
            'slot' => $correction->record->session->slot?->label,
            'current_status' => $correction->record->status->value,
            'requested_status' => $correction->requested_status->value,
            'reason' => $correction->reason,
            'requested_by' => $correction->requester?->name,
            'status' => $correction->status,
            'remarks' => $correction->remarks,
            'reviewed_by' => $correction->reviewer?->name,
            'created_at' => $correction->created_at?->toIso8601String(),
        ]);
    }

    public function decideCorrection(Request $request, string $correction): JsonResponse
    {
        Gate::authorize(AttendancePermissions::CorrectionsApprove);
        $validated = $request->validate(['approve' => ['required', 'boolean'], 'remarks' => ['nullable', 'string', 'max:500']]);
        $model = AttendanceCorrection::query()->with('record')->where('ulid', $correction)->firstOrFail();

        $this->attendance->decideCorrection($model, (bool) $validated['approve'], $validated['remarks'] ?? null, $this->user($request));

        return ApiResponse::data(['id' => $model->ulid, 'status' => $model->status]);
    }

    private function class(string $ulid): OfferingSection
    {
        return OfferingSection::query()->with(['offering.course', 'offering.term', 'section.batch'])->where('ulid', $ulid)->firstOrFail();
    }

    private function student(Request $request): Student
    {
        return Student::query()->where('user_id', $this->user($request)->id)->first() ?? throw new BusinessRuleException('You do not have a student record in this institution.', 'NOT_A_STUDENT', 404);
    }

    private function term(?string $ulid): ?Term
    {
        return $ulid ? Term::query()->where('ulid', $ulid)->first() : Term::query()->where('is_current', true)->first();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
