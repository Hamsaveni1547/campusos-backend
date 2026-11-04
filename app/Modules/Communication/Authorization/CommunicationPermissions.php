<?php

namespace App\Modules\Communication\Authorization;

use App\Shared\Authorization\PermissionProvider;

class CommunicationPermissions implements PermissionProvider
{
    public const TemplatesManage = 'communication.templates.manage';

    public const DeliveriesView = 'communication.deliveries.view';

    public const AnnouncementsPublish = 'communication.announcements.publish';

    public const AnnouncementsPublishAll = 'communication.announcements.publish_all';

    public static function module(): string
    {
        return 'communication';
    }

    public static function permissions(): array
    {
        return [
            self::TemplatesManage => ['description' => 'Customise email and SMS notification templates'],
            self::DeliveriesView => ['description' => 'View notification delivery status and failures'],
            self::AnnouncementsPublish => ['description' => 'Post announcements to departments, programmes, batches and sections'],
            self::AnnouncementsPublishAll => ['description' => 'Post announcements to everyone, all staff, all students or roles, and manage all announcements'],
        ];
    }
}
