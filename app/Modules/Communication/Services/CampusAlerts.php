<?php

namespace App\Modules\Communication\Services;

use App\Models\User;
use App\Modules\Academics\Models\FacultyAllocation;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Communication\Models\Announcement;
use App\Modules\Communication\Notifications\AttendanceShortageNotification;
use App\Modules\Communication\Notifications\FeeReminderNotification;
use App\Modules\Communication\Notifications\ResultsPublishedNotification;
use App\Modules\Communication\Notifications\TimetablePublishedNotification;
use App\Modules\Exams\Models\ExamCycle;
use App\Modules\Exams\Models\ExamResult;
use App\Modules\Finance\Models\FeeDemand;
use App\Modules\Institution\Models\Term;
use App\Modules\Mentoring\Models\MentorAssignment;
use App\Modules\Students\Models\Student;
use App\Modules\Timetable\Models\Timetable;
use Illuminate\Support\Facades\Notification;

/**
 * Notifications for things that happen in other modules, and the scheduled reminders.
 */
class CampusAlerts
{
    public function __construct(private AttendanceService $attendance, private AnnouncementService $announcements) {}

    public function resultsPublished(ExamCycle $cycle): int
    {
        $results = ExamResult::query()->with('student.user')->where('exam_cycle_id', $cycle->id)->get();

        foreach ($results as $result) {
            $result->student->user->notify(new ResultsPublishedNotification(['cycle' => $cycle->name, 'sgpa' => $result->sgpa ?? '—', 'cgpa' => $result->cgpa ?? '—'], '/my-results', 'See my results'));
        }

        return $results->count();
    }

    public function timetablePublished(Timetable $timetable): int
    {
        $timetable->loadMissing('section.batch', 'term');
        $studentIds = Student::query()->where('section_id', $timetable->section_id)->where('status', 'active')->pluck('user_id');
        $teacherIds = FacultyAllocation::query()->with('employee')
            ->whereHas('offeringSection', fn ($q) => $q->where('section_id', $timetable->section_id)->whereHas('offering', fn ($o) => $o->where('term_id', $timetable->term_id)))
            ->get()->pluck('employee.user_id');
        $users = User::query()->whereIn('id', $studentIds->merge($teacherIds)->filter()->unique())->get();

        Notification::send($users, new TimetablePublishedNotification(['section' => $timetable->section->batch->name.' '.$timetable->section->name, 'term' => $timetable->term->name], '/my-timetable', 'See timetable'));

        return $users->count();
    }

    /**
     * Weekly: students short of attendance in any course this term, copied to their mentor.
     */
    public function attendanceShortage(): int
    {
        $termId = Term::query()->where('is_current', true)->value('id');
        if (! $termId) {
            return 0;
        }

        $sent = 0;
        Student::query()->with('user')->where('status', 'active')->whereNotNull('section_id')->chunkById(200, function ($students) use ($termId, &$sent): void {
            $mentors = MentorAssignment::query()->current()->with('mentor.user')->whereIn('student_id', $students->pluck('id'))->get()->keyBy('student_id');

            foreach ($students as $student) {
                $short = collect($this->attendance->studentSummary($student, (int) $termId))->where('standing', 'shortage')->pluck('course.code');
                if ($short->isEmpty()) {
                    continue;
                }

                $values = ['student' => $student->user->name, 'courses' => $short->implode(', ')];
                $student->user->notify(new AttendanceShortageNotification($values, '/my-attendance', 'See attendance'));
                $mentor = $mentors->get($student->id)?->mentor->user;
                $mentor?->notify(new AttendanceShortageNotification($values, '/mentees/'.$student->ulid, 'Open mentee'));
                $sent++;
            }
        });

        return $sent;
    }

    /**
     * Daily: fees due in three days, and fees that became overdue a week ago.
     */
    public function feeReminders(): int
    {
        $demands = FeeDemand::query()->with('student.user')->whereIn('status', ['open', 'partly_paid'])
            ->where(fn ($q) => $q->whereDate('due_on', today()->addDays(3))->orWhereDate('due_on', today()->subDays(7)))->get();

        foreach ($demands as $demand) {
            $demand->student->user->notify(new FeeReminderNotification([
                'title' => $demand->title,
                'amount' => number_format($demand->balance(), 2),
                'due_on' => $demand->due_on->format('d M Y'),
                'state' => $demand->due_on->isPast() ? 'overdue' : 'due soon',
            ], '/my-fees', 'Pay now'));
        }

        return $demands->count();
    }

    /**
     * Every minute: publish scheduled announcements whose time has come.
     */
    public function publishDueAnnouncements(): int
    {
        $due = Announcement::query()->where('status', 'scheduled')->where('publish_at', '<=', now())->get();

        foreach ($due as $announcement) {
            $this->announcements->publish($announcement);
        }

        return $due->count();
    }
}
