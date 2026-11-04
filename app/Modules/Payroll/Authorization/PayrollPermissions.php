<?php

namespace App\Modules\Payroll\Authorization;

use App\Shared\Authorization\PermissionProvider;

class PayrollPermissions implements PermissionProvider
{
    public const SalariesManage = 'payroll.salaries.manage';

    public const RunsProcess = 'payroll.runs.process';

    public const RunsApprove = 'payroll.runs.approve';

    public const ReportsView = 'payroll.reports.view';

    public static function module(): string
    {
        return 'payroll';
    }

    public static function permissions(): array
    {
        return [
            self::SalariesManage => ['description' => 'Manage pay components and employee salaries', 'sensitive' => true],
            self::RunsProcess => ['description' => 'Prepare and calculate monthly payroll, and finalise approved runs', 'sensitive' => true],
            self::RunsApprove => ['description' => 'Approve a calculated payroll (not your own)', 'sensitive' => true],
            self::ReportsView => ['description' => 'View payroll runs, payslips and the salary register', 'sensitive' => true],
        ];
    }
}
