<?php

namespace App\Modules\Leave\Authorization;

use App\Shared\Authorization\PermissionProvider;

class LeavePermissions implements PermissionProvider
{
    public const Manage = 'leave.manage';

    public const Approve = 'leave.approve';

    public const ReportsView = 'leave.reports.view';

    public static function module(): string
    {
        return 'leave';
    }

    public static function permissions(): array
    {
        return [
            self::Manage => ['description' => 'Manage leave types and allot yearly balances'],
            self::Approve => ['description' => 'Approve or reject staff leave (not your own)'],
            self::ReportsView => ['description' => 'See staff leave applications and balances'],
        ];
    }
}
