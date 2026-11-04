<?php

namespace App\Modules\Mentoring\Services;

use App\Models\User;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Exams\Models\ExamResult;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use App\Modules\Institution\Models\Term;
use App\Modules\Mentoring\Authorization\MentoringPermissions as P;
use App\Modules\Mentoring\Models\MentorAssignment;
use App\Modules\Mentoring\Models\StudentConcern;
use App\Modules\Students\Models\Student;
use App\Shared\Exceptions\BusinessRuleException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Mentor allocation, access to mentees and the at-risk flags.
 */
class MentoringService
{
    public function __construct(private AttendanceService $attendance, private FinanceService $finance) {}

    /**
     * Shares the section's students who have no mentor among the mentors, in roll-number order.
     *
     * @param  list<int>  $sectionIds
     * @param  list<int>  $mentorIds  employee ids
     */
    public function allocate(array $sectionIds, array $mentorIds): int
    {
        $mentors = Employee::query()->whereIn('id', $mentorIds)->where('staff_type', 'teaching')->where('status', EmployeeStatus::Active)->pluck('id')->values();

        if ($mentors->isEmpty()) {
            throw new BusinessRuleException('Choose at least one active teacher as mentor.', 'NO_MENTORS', 422);
        }

        $students = Student::query()->whereIn('section_id', $sectionIds)->whereNotIn('status', ['graduated', 'dropped_out', 'transferred_out'])
            ->whereNotIn('id', MentorAssignment::query()->current()->select('student_id'))
            ->orderBy('section_id')->orderByRaw('roll_no is null, roll_no')->pluck('id');

        DB::transaction(function () use ($students, $mentors): void {
            foreach ($students->values() as $i => $studentId) {
                MentorAssignment::query()->create(['student_id' => $studentId, 'employee_id' => $mentors[$i % $mentors->count()], 'from_date' => today()->toDateString()]);
            }
        });

        return $students->count();
    }

    public function reassign(Student $student, Employee $mentor, ?string $reason): void
    {
        if ($mentor->staff_type->value !== 'teaching' || $mentor->status !== EmployeeStatus::Active) {
            throw new BusinessRuleException('The mentor must be an active teacher.', 'MENTOR_INVALID', 422);
        }

        $current = MentorAssignment::query()->current()->where('student_id', $student->id)->first();

        if ($current?->employee_id === $mentor->id) {
            throw new BusinessRuleException('This teacher is already the mentor.', 'NO_CHANGE', 422);
        }

        DB::transaction(function () use ($student, $mentor, $reason, $current): void {
            $current?->forceFill(['to_date' => today()->toDateString()])->save();
            MentorAssignment::query()->create(['student_id' => $student->id, 'employee_id' => $mentor->id, 'from_date' => today()->toDateString(), 'reason' => $reason]);
        });
    }

    public function isMentorOf(User $user, Student $student): bool
    {
        return MentorAssignment::query()->current()->where('student_id', $student->id)->whereHas('mentor', fn ($q) => $q->where('user_id', $user->id))->exists();
    }

    /**
     * Mentor of this student, or someone who sees all mentees.
     */
    public function canSee(User $user, Student $student): bool
    {
        return $user->can(P::ReportsView) || ($user->can(P::Mentor) && $this->isMentorOf($user, $student));
    }

    /**
     * Rule-based at-risk reasons per student (decision support only).
     *
     * @param  Collection<int, Student>  $students
     * @return array<int, list<string>> student id => reasons
     */
    public function flags(Collection $students): array
    {
        $term = Term::query()->where('is_current', true)->first();
        $backlogs = ExamResult::query()->whereIn('student_id', $students->pluck('id'))->orderBy('exam_cycle_id')->get()->keyBy('student_id')->map(fn (ExamResult $r): int => $r->backlogs);
        $concerns = StudentConcern::query()->whereIn('student_id', $students->pluck('id'))->where('status', 'open')->selectRaw('student_id, count(*) as total')->groupBy('student_id')->pluck('total', 'student_id');
        $flags = [];

        foreach ($students as $student) {
            $reasons = [];
            $short = $term === null ? [] : array_filter($this->attendance->studentSummary($student, $term->id), fn (array $c): bool => in_array($c['standing'], ['shortage', 'condonable'], true));

            if ($short !== []) {
                $reasons[] = 'Attendance below the minimum in '.count($short).' course(s)';
            }

            if (($backlogs[$student->id] ?? 0) > 0) {
                $reasons[] = $backlogs[$student->id].' backlog(s)';
            }

            if ($this->finance->totals($student)['overdue'] > 0) {
                $reasons[] = 'Fees overdue';
            }

            if (($concerns[$student->id] ?? 0) > 0) {
                $reasons[] = $concerns[$student->id].' open concern(s)';
            }

            $flags[$student->id] = $reasons;
        }

        return $flags;
    }
}
