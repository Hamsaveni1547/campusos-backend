<?php

namespace App\Modules\Timetable\Http\Controllers;

use App\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Institution\Models\Institution;
use App\Modules\Institution\Models\Term;
use App\Modules\Students\Models\Student;
use App\Modules\Timetable\Models\TimetableEntry;
use App\Shared\Http\ApiResponse;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\URL;

/**
 * My timetable as a calendar feed (iCalendar), so classes show up in Google Calendar or a phone calendar.
 * The feed URL is private: it is signed and tied to the user.
 *
 * @tags Timetable
 */
class CalendarFeedController extends Controller
{
    /**
     * A private link to subscribe to my timetable.
     */
    public function link(Request $request, CurrentInstitution $current): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $institution = $current->require();

        return ApiResponse::data(['url' => URL::signedRoute('api.v1.timetable.ics', ['campus' => $institution->ulid, 'member' => $user->ulid])]);
    }

    /**
     * The calendar file (signed URL, no sign-in).
     */
    public function feed(string $campus, string $member, CurrentInstitution $current): Response
    {
        $institutionModel = Institution::query()->withoutGlobalScopes()->where('ulid', $campus)->firstOrFail();
        $userModel = User::query()->withoutGlobalScopes()->where('ulid', $member)->firstOrFail();
        abort_unless($userModel->belongsToInstitution($institutionModel->id), 404);

        $body = $current->runAs($institutionModel, fn (): string => $this->calendar($userModel, $institutionModel));

        return response($body, 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Content-Disposition' => 'inline; filename="timetable.ics"']);
    }

    private function calendar(User $user, Institution $institution): string
    {
        $timezone = $institution->timezone ?: 'Asia/Kolkata';
        $term = Term::query()->where('is_current', true)->first();
        $student = Student::query()->where('user_id', $user->id)->whereNotNull('section_id')->first();
        $employee = $student === null ? Employee::query()->where('user_id', $user->id)->first() : null;

        $entries = $term === null || ($student === null && $employee === null) ? collect() : TimetableEntry::query()
            ->with(['slot', 'class.offering.course', 'class.section.batch', 'employee.user', 'room'])
            ->whereHas('timetable', fn ($q) => $q->where('status', 'published')->where('term_id', $term->id)->when($student, fn ($q) => $q->where('section_id', $student->section_id)))
            ->when($employee, fn ($q) => $q->where('employee_id', $employee->id))
            ->get();

        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//CampusOS//Timetable//EN', 'CALSCALE:GREGORIAN', 'X-WR-CALNAME:'.$this->escape($institution->code.' timetable'), 'X-WR-TIMEZONE:'.$timezone];

        foreach ($entries as $entry) {
            /** @var Term $term */
            $first = $term->starts_on->copy()->startOfDay();
            while ($first->dayOfWeekIso !== $entry->day_of_week) {
                $first->addDay();
            }
            $start = $first->format('Ymd').'T'.str_replace(':', '', substr($entry->slot->starts_at, 0, 5)).'00';
            $end = $first->format('Ymd').'T'.str_replace(':', '', substr($entry->slot->ends_at, 0, 5)).'00';
            $course = $entry->class->offering->course;
            $where = $entry->room?->code;
            $who = $student !== null ? $entry->employee?->user->name : $entry->class->section->batch->name.' '.$entry->class->section->name;

            $lines = [
                ...$lines,
                'BEGIN:VEVENT',
                'UID:'.$entry->ulid.'@campusos',
                'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
                'DTSTART;TZID='.$timezone.':'.$start,
                'DTEND;TZID='.$timezone.':'.$end,
                'RRULE:FREQ=WEEKLY;UNTIL='.$term->ends_on->copy()->endOfDay()->utc()->format('Ymd\THis\Z'),
                'SUMMARY:'.$this->escape($course->code.' '.$course->title.($entry->component !== 'theory' ? ' ('.$entry->component.')' : '')),
                ...($where ? ['LOCATION:'.$this->escape($where)] : []),
                ...($who ? ['DESCRIPTION:'.$this->escape($who)] : []),
                'END:VEVENT',
            ];
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines)."\r\n";
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $text);
    }
}
