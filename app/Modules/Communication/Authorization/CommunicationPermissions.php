<?php

namespace App\Modules\Communication\Authorization;

use App\Shared\Authorization\PermissionProvider;

class CommunicationPermissions implements PermissionProvider
{
    public const TemplatesManage = 'communication.templates.manage';

    public const DeliveriesView = 'communication.deliveries.view';

    public static function module(): string
    {
        return 'communication';
    }

    public static function permissions(): array
    {
        return [
            self::TemplatesManage => ['description' => 'Customise email and SMS notification templates'],
            self::DeliveriesView => ['description' => 'View notification delivery status and failures'],
        ];
    }
}
