<?php

namespace App\Modules\SystemAdmin\Authorization;

use App\Shared\Authorization\PermissionProvider;

class SystemAdminPermissions implements PermissionProvider
{
    public const SettingsView = 'admin.settings.view';

    public const SettingsManage = 'admin.settings.manage';

    public const AuditView = 'admin.audit.view';

    public const AuditExport = 'admin.audit.export';

    public const MonitoringView = 'admin.monitoring.view';

    public static function module(): string
    {
        return 'admin';
    }

    public static function permissions(): array
    {
        return [
            self::SettingsView => ['description' => 'View institution settings'],
            self::SettingsManage => ['description' => 'Change institution settings, including security policy', 'sensitive' => true],
            self::AuditView => ['description' => 'View the audit trail', 'sensitive' => true],
            self::AuditExport => ['description' => 'Export the audit trail', 'sensitive' => true],
            self::MonitoringView => ['description' => 'View system health'],
        ];
    }
}
