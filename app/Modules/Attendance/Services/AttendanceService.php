<?php

namespace App\Modules\Attendance\Services;

use App\Models\User;
use App\Modules\Academics\Models\CourseRegistration;
use App\Modules\Academics\Models\OfferingSection;
use App\Modules\Attendance\Authorization\AttendancePermissions;
use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\AttendanceCondonation;
use App\Modules\Attendance\Models\AttendanceCorrection;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Models\AttendanceSession;
use App\Modules\HR\Models\Employee;
use App\Modules\Students\Models\Student;
use App\Modules\Timetable\Models\TimeSlot;
use App\Modules\Timetable\Models\TimetableAdjustment;
use App\Modules\Timetable\Services\TimetableService;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Settings\SettingsRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Marking, percentages, shortage/condonation and corrections.
 */
class AttendanceService
{
    public function __construct(
        private SettingsRepository $settings,
        private TimetableService $timetables,
    ) {}

    // ----- policy ----------------------------------------------------------

    /**
     * Statuses that count as attended.
     *
     * @return list<string>
     */
    public function attendedStatuses(): array
    {
        return array_values(array_filter([
            AttendanceStatus::Present->value,
            (bool) $this->settings->get('attendance', 'late_counts_present') ? AttendanceStatus::Late->value : null,
            (bool) $this->settings->get('attendance', 'on_duty_counts_present') ? AttendanceStatus::OnDuty->value : null,
            (bool) $this->settings->get('attendance', 'medical_counts_present') ? AttendanceStatus::Medical->value : null,
        ]));
    }

    /**
     * ok | condoned | condonable | shortage | no_classes
     */
    public function standing(?float $percentage, bool $condoned): string
    {
        if ($percentage === null) {
            return 'no_classes';
        }

        if ($percentage >= (float) $this->settings->get('attendance', 'threshold')) {
            return 'ok';
        }

        if ($condoned) {
            return 'condoned';
        }

        return $percentage >= (float) $this->settings->get('attendance', 'condonation_min') ? 'condonable' : 'shortage';
    }

    // ----- roster and permission ------------------------------------------

    /**
     * Students registered for a class (not rejected), ordered by roll number.
     *
     * @return Collection<int, Student>
     */
    public function roster(OfferingSection $class): Collection
    {
        $ids = CourseRegistration::query()
            ->where('course_offering_id', $class->course_offering_id)
            ->whereHas('registration', fn ($query) => $query->where('section_id', $class->section_id)->where('status', '!=', 'rejected'))
            ->join('term_registrations', 'term_registrations.id', '=', 'course_registrations.term_registration_id')
            ->pluck('term_registrations.student_id');

        return Student::query()->with('user')->whereIn('id', $ids)->orderByRaw('roll_no is null, roll_no')->orderBy('register_no')->get();
    }

    public function canMark(User $user, OfferingSection $class, Carbon $date): bool
    {
        if ($user->can(AttendancePermissions::Edit)) {
            return true;
        }

        if (! $user->can(AttendancePermissions::Mark)) {
            return false;
        }

        if ($class->allocations()->whereHas('employee', fn ($query) => $query->where('user_id', $user->id))->exists()) {
            return true;
        }

        $employeeId = Employee::query()->where('user_id', $user->id)->value('id');

        return $employeeId !== null && TimetableAdjustment::query()
            ->where('offering_section_id', $class->id)
            ->whereIn('type', ['substituted', 'extra'])
            ->where('employee_id', $employeeId)
            ->whereDate('date', $date)
            ->exists();
    }

    public function isOpen(AttendanceSession $session): bool
    {
        $hours = (int) $this->settings->get('attendance', 'edit_window_hours');

        return $hours === 0 || now()->lte($session->date->copy()->endOfDay()->addHours($hours));
    }

    // ----- marking ---------------------------------------------------------

    /**
     * Create or update a session's attendance. Students not listed are marked present.
     *
     * @param  array<int, string>  $statuses  student id => status
     */
    public function mark(OfferingSection $class, Carbon $date, ?TimeSlot $slot, array $statuses, ?string $notes, User $user, string $component = 'theory'): AttendanceSession
    {
        $term = $class->offering->term;

        if ($date->isFuture() || $date->lt($term->starts_on) || $date->gt($term->ends_on)) {
            throw new BusinessRuleException('Attendance can be marked only for days of the term up to today.', 'DATE_INVALID', 422);
        }

        if (! $this->canMark($user, $class, $date)) {
            throw new BusinessRuleException('Only the teacher of this class (or that day\'s substitute) can mark attendance.', 'NOT_CLASS_TEACHER', 403);
        }

        $session = AttendanceSession::query()
            ->where('offering_section_id', $class->id)
            ->whereDate('date', $date)
            ->where('time_slot_id', $slot?->id)
            ->first();

        if ($session !== null && ! $this->isOpen($session) && ! $user->can(AttendancePermissions::Edit)) {
            throw new BusinessRuleException('The time to change this attendance has passed. Ask for a correction instead.', 'EDIT_WINDOW_CLOSED', 409);
        }

        $roster = $this->roster($class);

        if ($roster->isEmpty()) {
            throw new BusinessRuleException('No students are registered for this class yet.', 'NO_STUDENTS', 422);
        }

        $unknown = array_diff(array_keys($statuses), $roster->pluck('id')->all());

        if ($unknown !== []) {
            throw new BusinessRuleException('Some students are not in this class.', 'STUDENT_NOT_IN_CLASS', 422);
        }

        return DB::transaction(function () use ($session, $class, $date, $slot, $statuses, $notes, $user, $roster, $component): AttendanceSession {
            $session ??= new AttendanceSession(['offering_section_id' => $class->id, 'date' => $date, 'time_slot_id' => $slot?->id, 'starts_at' => $slot?->starts_at, 'component' => $component]);
            $session->notes = $notes;
            $session->forceFill(['marked_by' => $user->id, 'marked_at' => now()])->save();

            foreach ($roster as $student) {
                AttendanceRecord::query()->updateOrCreate(
                    ['attendance_session_id' => $session->id, 'student_id' => $student->id],
                    ['institution_id' => $session->institution_id, 'status' => $statuses[$student->id] ?? AttendanceStatus::Present->value],
                );
            }

            return $session;
        });
    }

    /**
     * Periods the teacher had in the last days (from the timetable) that are not marked yet.
     *
     * @return list<array<string, mixed>>
     */
    public function pending(Employee $employee, int $days = 7): array
    {
        $pending = [];

        for ($i = 0; $i < $days; $i++) {
            $date = now()->subDays($i)->startOfDay();

            foreach ($this->timetables->day($date, 'teacher', $employee->id, null) as $period) {
                if (in_array($period['status'], ['cancelled', 'handed_over'], true) || ($i === 0 && $period['starts_at'] > now()->format('H:i'))) {
                    continue;
                }

                $marked = AttendanceSession::query()
                    ->whereHas('class', fn ($query) => $query->where('ulid', $period['class_id']))
                    ->whereHas('slot', fn ($query) => $query->where('ulid', $period['slot_id']))
                    ->whereDate('date', $date)
                    ->exists();

                if (! $marked) {
                    $pending[] = $period + ['date' => $date->toDateString()];
                }
            }
        }

        return $pending;
    }

    // ----- percentages -----------------------------------------------------

    /**
     * Per student of a class: held, attended, percentage, condoned, standing.
     *
     * @return list<array<string, mixed>>
     */
    public function classSummary(OfferingSection $class): array
    {
        $held = AttendanceSession::query()->where('offering_section_id', $class->id)->count();
        $attended = AttendanceRecord::query()
            ->whereHas('session', fn ($query) => $query->where('offering_section_id', $class->id))
            ->whereIn('status', $this->attendedStatuses())
            ->selectRaw('student_id, count(*) as total')->groupBy('student_id')->pluck('total', 'student_id');
        $condoned = AttendanceCondonation::query()->where('offering_section_id', $class->id)->pluck('student_id')->all();

        return $this->roster($class)->map(function (Student $student) use ($held, $attended, $condoned): array {
            $count = (int) ($attended[$student->id] ?? 0);
            $percentage = $held === 0 ? null : round($count * 100 / $held, 2);
            $isCondoned = in_array($student->id, $condoned, true);

            return [
                'student_id' => $student->ulid,
                'register_no' => $student->register_no,
                'roll_no' => $student->roll_no,
                'name' => $student->user->name,
                'held' => $held,
                'attended' => $count,
                'percentage' => $percentage,
                'condoned' => $isCondoned,
                'standing' => $this->standing($percentage, $isCondoned),
            ];
        })->values()->all();
    }

    /**
     * Per class a student takes this term.
     *
     * @return list<array<string, mixed>>
     */
    public function studentSummary(Student $student, int $termId): array
    {
        $classes = OfferingSection::query()
            ->with('offering.course')
            ->where('section_id', $student->section_id)
            ->whereHas('offering', fn ($query) => $query->where('term_id', $termId))
            ->whereExists(fn ($query) => $query->select(DB::raw(1))->from('course_registrations')
                ->join('term_registrations', 'term_registrations.id', '=', 'course_registrations.term_registration_id')
                ->whereColumn('course_registrations.course_offering_id', 'offering_sections.course_offering_id')
                ->where('term_registrations.student_id', $student->id)
                ->where('term_registrations.status', '!=', 'rejected'))
            ->get();

        return $classes->map(function (OfferingSection $class) use ($student): array {
            $held = AttendanceSession::query()->where('offering_section_id', $class->id)->count();
            $attended = AttendanceRecord::query()->where('student_id', $student->id)->whereIn('status', $this->attendedStatuses())
                ->whereHas('session', fn ($query) => $query->where('offering_section_id', $class->id))->count();
            $percentage = $held === 0 ? null : round($attended * 100 / $held, 2);
            $condoned = AttendanceCondonation::query()->where('student_id', $student->id)->where('offering_section_id', $class->id)->exists();

            return [
                'class_id' => $class->ulid,
                'course' => ['code' => $class->offering->course->code, 'title' => $class->offering->course->title],
                'held' => $held,
                'attended' => $attended,
                'percentage' => $percentage,
                'condoned' => $condoned,
                'standing' => $this->standing($percentage, $condoned),
            ];
        })->sortBy('course.code')->values()->all();
    }

    // ----- corrections and condonation ------------------------------------

    public function requestCorrection(AttendanceRecord $record, AttendanceStatus $status, string $reason, User $user): AttendanceCorrection
    {
        if ($record->status === $status) {
            throw new BusinessRuleException('The attendance is already marked that way.', 'NO_CHANGE', 422);
        }

        if (AttendanceCorrection::query()->where('attendance_record_id', $record->id)->where('status', 'pending')->exists()) {
            throw new BusinessRuleException('A correction for this class is already waiting.', 'CORRECTION_PENDING', 409);
        }

        return AttendanceCorrection::query()->create(['attendance_record_id' => $record->id, 'requested_status' => $status, 'reason' => $reason, 'requested_by' => $user->id]);
    }

    public function decideCorrection(AttendanceCorrection $correction, bool $approve, ?string $remarks, User $reviewer): AttendanceCorrection
    {
        if ($correction->status !== 'pending') {
            throw new BusinessRuleException('This request has already been reviewed.', 'ALREADY_REVIEWED', 409);
        }

        if (! $approve && blank($remarks)) {
            throw new BusinessRuleException('Say why the request is rejected.', 'REMARKS_REQUIRED', 422);
        }

        DB::transaction(function () use ($correction, $approve, $remarks, $reviewer): void {
            if ($approve) {
                $correction->record->update(['status' => $correction->requested_status]);
            }

            $correction->forceFill(['status' => $approve ? 'approved' : 'rejected', 'remarks' => $remarks, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now()])->save();
        });

        return $correction;
    }

    public function condone(Student $student, OfferingSection $class, string $reason, ?string $feeReference, User $user): AttendanceCondonation
    {
        $row = collect($this->classSummary($class))->firstWhere('student_id', $student->ulid);

        if ($row === null) {
            throw new BusinessRuleException('The student is not in this class.', 'STUDENT_NOT_IN_CLASS', 422);
        }

        if ($row['standing'] !== 'condonable') {
            throw new BusinessRuleException('Only a shortage within the condonation band can be condoned (now '.($row['percentage'] ?? 0).'%).', 'NOT_CONDONABLE', 422);
        }

        return AttendanceCondonation::query()->create([
            'student_id' => $student->id,
            'offering_section_id' => $class->id,
            'percentage_at_grant' => $row['percentage'],
            'reason' => $reason,
            'fee_reference' => $feeReference,
            'granted_by' => $user->id,
        ]);
    }
}
