<?php

namespace App\Modules\Library\Authorization;

use App\Shared\Authorization\PermissionProvider;

class LibraryPermissions implements PermissionProvider
{
    public const CatalogueManage = 'library.catalogue.manage';

    public const CirculationManage = 'library.circulation.manage';

    public const FinesWaive = 'library.fines.waive';

    public const ReportsView = 'library.reports.view';

    public static function module(): string
    {
        return 'library';
    }

    public static function permissions(): array
    {
        return [
            self::CatalogueManage => ['description' => 'Add and edit books and copies'],
            self::CirculationManage => ['description' => 'Issue, return and renew books, and collect fines'],
            self::FinesWaive => ['description' => 'Waive library fines', 'sensitive' => true],
            self::ReportsView => ['description' => 'See loans, overdue books and member dues'],
        ];
    }
}
