<?php

namespace App\Modules\HR\Authorization;

use App\Shared\Authorization\PermissionProvider;

class HRPermissions implements PermissionProvider
{
    public const EmployeesView = 'hr.employees.view';

    public const EmployeesManage = 'hr.employees.manage';

    public const EmployeesImport = 'hr.employees.import';

    public const DesignationsManage = 'hr.designations.manage';

    public static function module(): string
    {
        return 'hr';
    }

    public static function permissions(): array
    {
        return [
            self::EmployeesView => ['description' => 'View staff records'],
            self::EmployeesManage => ['description' => 'Add staff, edit staff records and record exits', 'sensitive' => true],
            self::EmployeesImport => ['description' => 'Bulk import staff from CSV'],
            self::DesignationsManage => ['description' => 'Manage designations (job titles)'],
        ];
    }
}
