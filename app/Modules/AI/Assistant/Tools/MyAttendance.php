<?php

namespace App\Modules\AI\Assistant\Tools;

use App\Models\User;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Institution\Models\Term;

class MyAttendance extends StudentTool
{
    public function __construct(private AttendanceService $attendance) {}

    public function name(): string
    {
        return 'my_attendance';
    }

    public function description(): string
    {
        return "The student's attendance this term, per course: classes held, attended, percentage and standing (ok, condonable, shortage).";
    }

    public function run(User $user, array $arguments): array
    {
        $term = Term::query()->where('is_current', true)->first();
        $student = $this->student($user);

        if ($term === null || $student === null) {
            return ['message' => 'No current term.'];
        }

        return [
            'term' => $term->name,
            'courses' => array_map(fn (array $c): array => [
                'course' => $c['course']['code'].' '.$c['course']['title'], 'held' => $c['held'], 'attended' => $c['attended'], 'percentage' => $c['percentage'], 'standing' => $c['standing'],
            ], $this->attendance->studentSummary($student, $term->id)),
        ];
    }
}
