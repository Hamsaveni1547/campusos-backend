<?php

namespace App\Modules\Assessment\Authorization;

use App\Shared\Authorization\PermissionProvider;

class AssessmentPermissions implements PermissionProvider
{
    public const MarksEnter = 'assessment.marks.enter';

    public const MarksVerify = 'assessment.marks.verify';

    public const MarksLock = 'assessment.marks.lock';

    public const CorrectionsApprove = 'assessment.corrections.approve';

    public const SchemesManage = 'assessment.schemes.manage';

    public const ReportsView = 'assessment.reports.view';

    public static function module(): string
    {
        return 'assessment';
    }

    public static function permissions(): array
    {
        return [
            self::MarksEnter => ['description' => 'Create assessments and assignments, enter marks and submit internal marks for classes you teach'],
            self::MarksVerify => ['description' => 'Verify submitted internal marks (not your own)', 'sensitive' => true],
            self::MarksLock => ['description' => 'Lock verified internal marks for examinations', 'sensitive' => true],
            self::CorrectionsApprove => ['description' => 'Approve mark corrections after submission', 'sensitive' => true],
            self::SchemesManage => ['description' => 'Set the internal marks scheme and course outcomes of any course'],
            self::ReportsView => ['description' => 'View marks and internal marks of any class'],
        ];
    }
}
