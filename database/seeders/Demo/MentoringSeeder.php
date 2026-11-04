<?php

namespace Database\Seeders\Demo;

use App\Modules\HR\Models\Employee;
use App\Modules\Institution\Models\Section;
use App\Modules\Mentoring\Models\MentorAssignment;
use App\Modules\Mentoring\Models\MentoringMeeting;
use App\Modules\Mentoring\Models\MentoringNote;
use App\Modules\Mentoring\Models\StudentConcern;
use App\Modules\Mentoring\Services\MentoringService;
use App\Modules\Students\Models\Student;
use Database\Seeders\Support\CampusSeeder;

/**
 * Mentors for every active section (teachers of the programme's department), with notes,
 * open and resolved concerns, and meetings in every state, including a request from student@.
 */
class MentoringSeeder extends CampusSeeder
{
    protected function seed(): void
    {
        if (MentorAssignment::query()->exists()) {
            return;
        }

        $mentoring = app(MentoringService::class);

        foreach (Section::query()->with('batch.programme')->get() as $section) {
            $mentorIds = Employee::query()->where('department_id', $section->batch->programme->department_id)
                ->where('staff_type', 'teaching')->where('status', 'active')->orderBy('id')->limit(4)->pluck('id')->all();

            if ($mentorIds !== []) {
                $mentoring->allocate([$section->id], $mentorIds);
            }
        }

        $kavya = $this->user('kavya.shetty@'.self::DOMAIN);
        $employee = Employee::query()->where('user_id', $kavya->id)->first();

        if (! $employee) {
            return;
        }

        $student = Student::query()->whereHas('user', fn ($q) => $q->where('email', 'student@'.self::DOMAIN))->first();

        if ($student) {
            $current = MentorAssignment::query()->current()->where('student_id', $student->id)->first();
            if ($current?->employee_id !== $employee->id) {
                $mentoring->reassign($student, $employee, 'Demo: mentor for the sample student');
            }
        }

        $menteeIds = MentorAssignment::query()->current()->where('employee_id', $employee->id)->orderBy('id')->limit(6)->pluck('student_id')->all();

        if ($menteeIds === []) {
            return;
        }

        $this->as($kavya, function () use ($menteeIds, $student): void {
            [$first, $second] = [$menteeIds[0], $menteeIds[1] ?? $menteeIds[0]];

            (new MentoringNote)->forceFill(['student_id' => $first, 'visibility' => 'shared', 'body' => 'Discussed study plan for internals. Will attend the doubt-clearing sessions on Saturdays.'])->save();
            (new MentoringNote)->forceFill(['student_id' => $second, 'visibility' => 'confidential', 'body' => 'Family health issue at home; may miss classes for two weeks. Informed only the counsellor.'])->save();

            (new StudentConcern)->forceFill(['student_id' => $first, 'category' => 'attendance', 'description' => 'Attendance below 75% in two courses.', 'action_plan' => 'Weekly check-in; meet course teachers.', 'review_on' => now()->subDays(2)->toDateString(), 'status' => 'open'])->save();
            (new StudentConcern)->forceFill(['student_id' => $second, 'category' => 'financial', 'description' => 'Second instalment of fees delayed.', 'action_plan' => 'Spoke to accounts about instalments.', 'status' => 'resolved', 'outcome' => 'Paid in two parts with the accounts office.', 'resolved_at' => now()->subDays(10)])->save();

            $meetings = [
                ['status' => 'held', 'scheduled_at' => now()->subWeeks(2)->setTime(15, 0), 'agenda' => 'Start-of-semester check-in', 'minutes' => 'Shared goals for the semester; everyone has access to the course material.', 'attended_ids' => array_slice($menteeIds, 0, -1), 'student_ids' => $menteeIds],
                ['status' => 'scheduled', 'scheduled_at' => now()->addDays(3)->setTime(15, 30), 'agenda' => 'Internal assessment preparation', 'student_ids' => $menteeIds],
                ['status' => 'cancelled', 'scheduled_at' => now()->subWeek()->setTime(11, 0), 'agenda' => 'Project topic discussion', 'student_ids' => [$first]],
            ];
            if ($student) {
                $meetings[] = ['status' => 'requested', 'scheduled_at' => null, 'agenda' => 'Guidance on choosing electives', 'student_ids' => [$student->id]];
            }

            foreach ($meetings as $meeting) {
                (new MentoringMeeting)->forceFill(['employee_id' => Employee::query()->where('user_id', auth()->id())->value('id'), ...$meeting])->save();
            }
        });
    }
}
