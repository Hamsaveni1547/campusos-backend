<?php

namespace App\Modules\Academics\Authorization;

use App\Shared\Authorization\PermissionProvider;

class AcademicsPermissions implements PermissionProvider
{
    public const View = 'academics.catalogue.view';

    public const CoursesManage = 'academics.courses.manage';

    public const CurriculumManage = 'academics.curriculum.manage';

    public const CurriculumApprove = 'academics.curriculum.approve';

    public const OfferingsManage = 'academics.offerings.manage';

    public const AllocationsManage = 'academics.allocations.manage';

    public const SyllabusTrack = 'academics.syllabus.track';

    public const MaterialsManage = 'academics.materials.manage';

    public static function module(): string
    {
        return 'academics';
    }

    public static function permissions(): array
    {
        return [
            self::View => ['description' => 'View courses, curricula, offerings and allocations'],
            self::CoursesManage => ['description' => 'Manage the course catalogue and syllabus'],
            self::CurriculumManage => ['description' => 'Prepare curriculum versions and submit them for approval'],
            self::CurriculumApprove => ['description' => 'Approve or return curriculum versions'],
            self::OfferingsManage => ['description' => 'Offer courses in a term and assign sections'],
            self::AllocationsManage => ['description' => 'Allocate faculty to classes'],
            self::SyllabusTrack => ['description' => 'Mark syllabus topics covered for allocated classes'],
            self::MaterialsManage => ['description' => 'Share course materials with students of allocated classes'],
        ];
    }
}
