<?php

namespace App\Modules\Institution\Authorization;

use App\Shared\Authorization\PermissionProvider;

class InstitutionPermissions implements PermissionProvider
{
    public const ProfileManage = 'institution.profile.manage';

    public static function module(): string
    {
        return 'institution';
    }

    public static function permissions(): array
    {
        return [
            self::ProfileManage => ['description' => 'Edit the institution profile and branding'],
        ];
    }
}
