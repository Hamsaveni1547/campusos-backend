<?php

namespace App\Modules\AI\Assistant\Tools;

use App\Models\User;
use App\Modules\AI\Contracts\AssistantTool;
use App\Modules\Reports\Services\DashboardCards;
use stdClass;

/**
 * The person's home-page figures: their own, plus the college figures their role may see.
 */
class MyDashboard implements AssistantTool
{
    public function __construct(private DashboardCards $cards) {}

    public function name(): string
    {
        return 'my_dashboard';
    }

    public function description(): string
    {
        return 'Key figures for this person: unread announcements, and for staff the college numbers their role may see (active students and staff, fees collected this month and overdue, leave waiting for approval, applications, open complaints, overdue books, repair requests).';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new stdClass];
    }

    public function availableTo(User $user): bool
    {
        return true;
    }

    public function run(User $user, array $arguments): array
    {
        return ['figures' => array_map(fn (array $c): array => ['what' => $c['label'], 'value' => $c['value'], 'note' => $c['hint']], $this->cards->for($user))];
    }
}
