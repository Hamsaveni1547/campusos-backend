<?php

namespace App\Modules\Identity\Authorization;

use App\Shared\Authorization\PermissionProvider;

class IdentityPermissions implements PermissionProvider
{
    public const UsersView = 'identity.users.view';

    public const UsersCreate = 'identity.users.create';

    public const UsersUpdate = 'identity.users.update';

    public const UsersDeactivate = 'identity.users.deactivate';

    public const UsersImport = 'identity.users.import';

    public const UsersResetPassword = 'identity.users.reset_password';

    public const UsersUnlock = 'identity.users.unlock';

    public const RolesView = 'identity.roles.view';

    public const RolesManage = 'identity.roles.manage';

    public const RoleAssignmentsView = 'identity.role_assignments.view';

    public const RoleAssignmentsManage = 'identity.role_assignments.manage';

    public const SecurityEventsView = 'identity.security_events.view';

    public static function module(): string
    {
        return 'identity';
    }

    public static function permissions(): array
    {
        return [
            self::UsersView => ['description' => 'View user accounts of the institution'],
            self::UsersCreate => ['description' => 'Create user accounts'],
            self::UsersUpdate => ['description' => 'Edit user account details'],
            self::UsersDeactivate => ['description' => 'Activate and deactivate user accounts', 'sensitive' => true],
            self::UsersImport => ['description' => 'Bulk import user accounts from CSV'],
            self::UsersResetPassword => ['description' => 'Reset passwords and MFA of other users', 'sensitive' => true],
            self::UsersUnlock => ['description' => 'Unlock locked user accounts'],
            self::RolesView => ['description' => 'View roles and their permissions'],
            self::RolesManage => ['description' => 'Create, edit and delete custom roles', 'sensitive' => true],
            self::RoleAssignmentsView => ['description' => 'View who holds which role'],
            self::RoleAssignmentsManage => ['description' => 'Assign and revoke roles', 'sensitive' => true],
            self::SecurityEventsView => ['description' => 'View login history and security events', 'sensitive' => true],
        ];
    }
}
