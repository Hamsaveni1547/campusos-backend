<?php

namespace App\Modules\Mentoring\Authorization;

use App\Shared\Authorization\PermissionProvider;

class MentoringPermissions implements PermissionProvider
{
    public const Allocate = 'mentoring.allocate';

    public const Mentor = 'mentoring.mentor';

    public const Confidential = 'mentoring.notes.confidential';

    public const ReportsView = 'mentoring.reports.view';

    public static function module(): string
    {
        return 'mentoring';
    }

    public static function permissions(): array
    {
        return [
            self::Allocate => ['description' => 'Assign and reassign mentors'],
            self::Mentor => ['description' => 'Mentor students: notes, concerns and meetings for your mentees'],
            self::Confidential => ['description' => 'Read confidential mentoring notes (counsellors)', 'sensitive' => true],
            self::ReportsView => ['description' => 'See every mentee, shared notes and the at-risk list'],
        ];
    }
}
