<?php

namespace App\Modules\Finance\Authorization;

use App\Shared\Authorization\PermissionProvider;

class FinancePermissions implements PermissionProvider
{
    public const StructuresManage = 'finance.structures.manage';

    public const DemandsManage = 'finance.demands.manage';

    public const PaymentsCollect = 'finance.payments.collect';

    public const PaymentsCancel = 'finance.payments.cancel';

    public const ConcessionsApprove = 'finance.concessions.approve';

    public const ReportsView = 'finance.reports.view';

    public static function module(): string
    {
        return 'finance';
    }

    public static function permissions(): array
    {
        return [
            self::StructuresManage => ['description' => 'Manage fee heads and fee structures'],
            self::DemandsManage => ['description' => 'Raise fee demands, add one-off charges, request concessions and cancel demands'],
            self::PaymentsCollect => ['description' => 'Record payments and issue receipts'],
            self::PaymentsCancel => ['description' => 'Cancel receipts (the payment is reversed)', 'sensitive' => true],
            self::ConcessionsApprove => ['description' => 'Approve or reject fee concessions', 'sensitive' => true],
            self::ReportsView => ['description' => 'View student fee accounts, collections and dues'],
        ];
    }
}
