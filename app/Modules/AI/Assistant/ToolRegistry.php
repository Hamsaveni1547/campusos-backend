<?php

namespace App\Modules\AI\Assistant;

use App\Models\User;
use App\Modules\AI\Assistant\Tools\ClassesToMark;
use App\Modules\AI\Assistant\Tools\LatestAnnouncements;
use App\Modules\AI\Assistant\Tools\MyAttendance;
use App\Modules\AI\Assistant\Tools\MyDashboard;
use App\Modules\AI\Assistant\Tools\MyFees;
use App\Modules\AI\Assistant\Tools\MyLibraryLoans;
use App\Modules\AI\Assistant\Tools\MyResults;
use App\Modules\AI\Assistant\Tools\MyTimetable;
use App\Modules\AI\Contracts\AssistantTool;

/**
 * The assistant's tools. Add a tool by writing an AssistantTool class and listing it here.
 */
class ToolRegistry
{
    /** @var list<class-string<AssistantTool>> */
    public const TOOLS = [
        MyAttendance::class,
        MyTimetable::class,
        MyFees::class,
        MyResults::class,
        MyLibraryLoans::class,
        ClassesToMark::class,
        MyDashboard::class,
        LatestAnnouncements::class,
    ];

    /**
     * @return array<string, AssistantTool> name => tool, only those that apply to the person
     */
    public function for(User $user): array
    {
        return collect(self::TOOLS)->map(fn (string $class): AssistantTool => app($class))
            ->filter(fn (AssistantTool $tool): bool => $tool->availableTo($user))
            ->mapWithKeys(fn (AssistantTool $tool): array => [$tool->name() => $tool])->all();
    }
}
