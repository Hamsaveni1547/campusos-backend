<?php

namespace App\Modules\Attendance\Authorization;

use App\Shared\Authorization\PermissionProvider;

class AttendancePermissions implements PermissionProvider
{
    public const Mark = 'attendance.records.mark';

    public const Edit = 'attendance.records.edit';

    public const CorrectionsApprove = 'attendance.corrections.approve';

    public const CondonationManage = 'attendance.condonation.manage';

    public const ReportsView = 'attendance.reports.view';

    public static function module(): string
    {
        return 'attendance';
    }

    public static function permissions(): array
    {
        return [
            self::Mark => ['description' => 'Mark attendance for classes you teach (within the edit window)'],
            self::Edit => ['description' => 'Mark or change attendance for any class, at any time'],
            self::CorrectionsApprove => ['description' => 'Approve or reject attendance correction requests'],
            self::CondonationManage => ['description' => 'Condone attendance shortage within the allowed band'],
            self::ReportsView => ['description' => 'View attendance summaries and shortage lists'],
        ];
    }
}
