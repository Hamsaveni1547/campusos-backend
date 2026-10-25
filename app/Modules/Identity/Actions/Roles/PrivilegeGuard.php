<?php

namespace App\Modules\Identity\Actions\Roles;

use App\Models\User;
use App\Modules\Identity\Models\Role;
use App\Shared\Exceptions\BusinessRuleException;

/**
 * Prevents privilege escalation: nobody changes their own roles, and nobody grants
 * a role carrying permissions they do not hold themselves.
 */
class PrivilegeGuard
{
    public function assertCanGrant(User $actor, User $target, Role $role): void
    {
        if ($actor->is($target)) {
            throw new BusinessRuleException('You cannot change your own roles.', 'SELF_ROLE_CHANGE', 403);
        }

        $this->assertHoldsAll($actor, $role->permissions->pluck('name')->all(), 'You cannot grant a role that has permissions you do not have.');
    }

    public function assertCanRevoke(User $actor, User $target, Role $role): void
    {
        if ($actor->is($target)) {
            throw new BusinessRuleException('You cannot change your own roles.', 'SELF_ROLE_CHANGE', 403);
        }

        $this->assertHoldsAll($actor, $role->permissions->pluck('name')->all(), 'You cannot revoke a role that has permissions you do not have.');
    }

    /**
     * @param  array<int, string>  $permissions
     */
    public function assertHoldsAll(User $actor, array $permissions, string $message = 'You cannot grant permissions you do not have.'): void
    {
        if ($actor->is_platform_admin) {
            return;
        }

        $missing = array_values(array_filter($permissions, fn (string $permission): bool => ! $actor->hasPermissionTo($permission)));

        if ($missing !== []) {
            throw new BusinessRuleException($message, 'PRIVILEGE_ESCALATION', 403, ['missing_permissions' => $missing]);
        }
    }
}
