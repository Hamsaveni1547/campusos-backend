<?php

namespace App\Modules\Institution\Authorization;

use App\Shared\Authorization\PermissionProvider;

/**
 * Viewing the academic structure is open to every member; these permissions cover changes.
 */
class InstitutionPermissions implements PermissionProvider
{
    public const ProfileManage = 'institution.profile.manage';

    public const DepartmentsManage = 'institution.departments.manage';

    public const ProgrammesManage = 'institution.programmes.manage';

    public const CalendarManage = 'institution.calendar.manage';

    public const BatchesManage = 'institution.batches.manage';

    public const RoomsManage = 'institution.rooms.manage';

    public static function module(): string
    {
        return 'institution';
    }

    public static function permissions(): array
    {
        return [
            self::ProfileManage => ['description' => 'Edit the institution profile and branding'],
            self::DepartmentsManage => ['description' => 'Create and edit departments and assign heads of department', 'sensitive' => true],
            self::ProgrammesManage => ['description' => 'Create and edit programmes'],
            self::CalendarManage => ['description' => 'Manage academic years, terms, holidays and calendar events'],
            self::BatchesManage => ['description' => 'Manage batches and sections'],
            self::RoomsManage => ['description' => 'Manage rooms, laboratories and halls'],
        ];
    }
}
