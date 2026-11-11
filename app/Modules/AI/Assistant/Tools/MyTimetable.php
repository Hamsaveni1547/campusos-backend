<?php

namespace App\Modules\AI\Assistant\Tools;

use App\Models\User;
use App\Modules\AI\Contracts\AssistantTool;
use App\Modules\HR\Models\Employee;
use App\Modules\Institution\Models\Term;
use App\Modules\Students\Models\Student;
use App\Modules\Timetable\Services\TimetableService;
use Illuminate\Support\Carbon;

/**
 * Classes on one day for a student (their section) or a teacher, with that day's changes applied.
 */
class MyTimetable implements AssistantTool
{
    public function __construct(private TimetableService $timetables) {}

    public function name(): string
    {
        return 'my_timetable';
    }

    public function description(): string
    {
        return 'Classes on one day for the signed-in student or teacher, including cancellations and substitutions.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['date' => ['type' => 'string', 'description' => 'YYYY-MM-DD; today when not given']]];
    }

    public function availableTo(User $user): bool
    {
        return $this->target($user) !== null;
    }

    public function run(User $user, array $arguments): array
    {
        $target = $this->target($user);

        if ($target === null) {
            return [];
        }

        $date = rescue(fn (): Carbon => Carbon::parse((string) ($arguments['date'] ?? 'today'))->startOfDay(), today(), false);
        $termId = Term::query()->where('is_current', true)->value('id');
        $keep = array_flip(['slot', 'starts_at', 'ends_at', 'course', 'section', 'teacher', 'room', 'status', 'reason']);

        return [
            'date' => $date->toDateString(),
            'day' => $date->format('l'),
            'classes' => array_map(fn (array $p): array => array_intersect_key($p, $keep), $this->timetables->day($date, $target[0], $target[1], $termId === null ? null : (int) $termId)),
        ];
    }

    /**
     * @return array{0: 'section'|'teacher', 1: int}|null
     */
    private function target(User $user): ?array
    {
        $section = Student::query()->where('user_id', $user->id)->value('section_id');
        if ($section !== null) {
            return ['section', (int) $section];
        }

        $employee = Employee::query()->where('user_id', $user->id)->value('id');

        return $employee === null ? null : ['teacher', (int) $employee];
    }
}
