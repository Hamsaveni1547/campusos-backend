<?php

namespace App\Modules\Timetable\Authorization;

use App\Shared\Authorization\PermissionProvider;

class TimetablePermissions implements PermissionProvider
{
    public const View = 'timetable.view';

    public const Manage = 'timetable.manage';

    public const Publish = 'timetable.publish';

    public const Adjust = 'timetable.adjust';

    public static function module(): string
    {
        return 'timetable';
    }

    public static function permissions(): array
    {
        return [
            self::View => ['description' => 'View any section, teacher or room timetable'],
            self::Manage => ['description' => 'Set up periods and build section timetables'],
            self::Publish => ['description' => 'Publish timetables (no clashes allowed)'],
            self::Adjust => ['description' => 'Cancel classes, assign substitutes and add extra classes'],
        ];
    }
}
