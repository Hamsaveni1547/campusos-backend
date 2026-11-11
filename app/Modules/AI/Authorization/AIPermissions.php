<?php

namespace App\Modules\AI\Authorization;

use App\Shared\Authorization\PermissionProvider;

/**
 * AI features follow the permissions of the screen they appear on (e.g. posting announcements);
 * these cover the assistant and the usage page.
 */
class AIPermissions implements PermissionProvider
{
    public const AssistantUse = 'ai.assistant.use';

    public const UsageView = 'ai.usage.view';

    public static function module(): string
    {
        return 'ai';
    }

    public static function permissions(): array
    {
        return [
            self::AssistantUse => ['description' => 'Ask the CampusOS assistant about your own attendance, timetable, fees, results and work'],
            self::UsageView => ['description' => 'See how much AI is used, by feature, and whether calls fail'],
        ];
    }
}
