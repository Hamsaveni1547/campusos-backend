<?php

namespace App\Modules\AI\Assistant\Tools;

use App\Models\User;
use App\Modules\AI\Contracts\AssistantTool;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\HR\Models\Employee;
use stdClass;

class ClassesToMark implements AssistantTool
{
    public function __construct(private AttendanceService $attendance) {}

    public function name(): string
    {
        return 'classes_to_mark';
    }

    public function description(): string
    {
        return 'For a teacher: classes in the last 7 days from the timetable whose attendance is not marked yet.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new stdClass];
    }

    public function availableTo(User $user): bool
    {
        return $user->can('attendance.records.mark') && $this->employee($user) !== null;
    }

    public function run(User $user, array $arguments): array
    {
        $employee = $this->employee($user);
        $keep = array_flip(['date', 'slot', 'starts_at', 'course', 'section']);

        return ['classes' => $employee === null ? [] : array_map(fn (array $p): array => array_intersect_key($p, $keep), $this->attendance->pending($employee))];
    }

    private function employee(User $user): ?Employee
    {
        return Employee::query()->where('user_id', $user->id)->first();
    }
}
