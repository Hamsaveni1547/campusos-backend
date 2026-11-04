<?php

namespace App\Modules\Assets\Authorization;

use App\Shared\Authorization\PermissionProvider;

class AssetPermissions implements PermissionProvider
{
    public const Manage = 'assets.manage';

    public const MaintenanceManage = 'assets.maintenance.manage';

    public const ReportsView = 'assets.reports.view';

    public const RoomsBook = 'assets.rooms.book';

    public static function module(): string
    {
        return 'assets';
    }

    public static function permissions(): array
    {
        return [
            self::Manage => ['description' => 'Maintain the asset register'],
            self::MaintenanceManage => ['description' => 'Assign, resolve and close maintenance requests'],
            self::ReportsView => ['description' => 'See assets, maintenance requests and bookings'],
            self::RoomsBook => ['description' => 'Book labs and rooms for extra sessions or events'],
        ];
    }
}
