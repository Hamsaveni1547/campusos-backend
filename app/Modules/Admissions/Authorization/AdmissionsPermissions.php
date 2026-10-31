<?php

namespace App\Modules\Admissions\Authorization;

use App\Shared\Authorization\PermissionProvider;

class AdmissionsPermissions implements PermissionProvider
{
    public const CyclesManage = 'admissions.cycles.manage';

    public const ApplicationsView = 'admissions.applications.view';

    public const ApplicationsVerify = 'admissions.applications.verify';

    public const ApplicationsProcess = 'admissions.applications.process';

    public const SeatsAllocate = 'admissions.seats.allocate';

    public const ManagementApprove = 'admissions.management.approve';

    public const Confirm = 'admissions.confirm';

    public static function module(): string
    {
        return 'admissions';
    }

    public static function permissions(): array
    {
        return [
            self::CyclesManage => ['description' => 'Set up admission cycles and the seat matrix'],
            self::ApplicationsView => ['description' => 'View applications and applicant documents', 'sensitive' => true],
            self::ApplicationsVerify => ['description' => 'Verify documents, request corrections and decide eligibility'],
            self::ApplicationsProcess => ['description' => 'Reject applications'],
            self::SeatsAllocate => ['description' => 'Allocate seats and issue offers'],
            self::ManagementApprove => ['description' => 'Approve management and NRI quota applications'],
            self::Confirm => ['description' => 'Record the admission fee and confirm admission', 'sensitive' => true],
        ];
    }
}
