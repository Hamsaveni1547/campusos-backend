<?php

namespace App\Modules\Identity\Actions\Roles;

use App\Models\User;
use App\Modules\Identity\Authorization\SystemRole;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Identity\Services\RoleSynchronizer;
use App\Shared\Audit\SecurityEventType;
use App\Shared\Audit\SecurityLogger;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Institution-defined roles. System roles are maintained in code and cannot be edited here.
 */
class ManageCustomRoles
{
    public function __construct(
        private CurrentInstitution $currentInstitution,
        private PrivilegeGuard $privilegeGuard,
        private RoleSynchronizer $synchronizer,
        private SecurityLogger $securityLogger,
    ) {}

    /**
     * @param  array{display_name: string, name?: string|null, description?: string|null, is_department_scoped?: bool}  $data
     * @param  array<int, string>  $permissions
     */
    public function create(array $data, array $permissions, User $actor): Role
    {
        $institution = $this->currentInstitution->require();
        $this->privilegeGuard->assertHoldsAll($actor, $permissions);

        $name = Str::slug($data['name'] ?? $data['display_name'], '_');

        if (SystemRole::tryFrom($name) !== null) {
            throw new BusinessRuleException('This name is reserved for a built-in role.', 'ROLE_NAME_RESERVED', 422);
        }

        $exists = Role::query()->where('institution_id', $institution->id)->where('name', $name)->exists();

        if ($exists) {
            throw new BusinessRuleException('A role with this name already exists.', 'ROLE_NAME_TAKEN', 409);
        }

        return DB::transaction(function () use ($institution, $name, $data, $permissions): Role {
            /** @var Role $role */
            $role = Role::query()->create([
                'institution_id' => $institution->id,
                'name' => $name,
                'guard_name' => 'web',
                'display_name' => $data['display_name'],
                'description' => $data['description'] ?? null,
                'is_system' => false,
                'is_active' => true,
                'is_department_scoped' => (bool) ($data['is_department_scoped'] ?? false),
            ]);

            $role->syncPermissions($permissions);

            return $role;
        });
    }

    /**
     * @param  array{display_name?: string, description?: string|null, is_department_scoped?: bool, is_active?: bool}  $data
     * @param  array<int, string>|null  $permissions
     */
    public function update(Role $role, array $data, ?array $permissions, User $actor): Role
    {
        $this->assertCustom($role);

        if ($permissions !== null) {
            $current = $role->permissions->pluck('name')->all();
            $this->privilegeGuard->assertHoldsAll($actor, array_values(array_diff($permissions, $current)));
            $this->privilegeGuard->assertHoldsAll($actor, array_values(array_diff($current, $permissions)), 'You cannot remove permissions you do not have.');
        }

        $activeChanged = array_key_exists('is_active', $data) && (bool) $data['is_active'] !== $role->is_active;

        DB::transaction(function () use ($role, $data, $permissions, $actor): void {
            $role->fill(array_intersect_key($data, array_flip(['display_name', 'description', 'is_department_scoped', 'is_active'])))->save();

            if ($permissions !== null) {
                $before = $role->permissions->pluck('name')->sort()->values()->all();
                $role->syncPermissions($permissions);
                $after = collect($permissions)->sort()->values()->all();

                if ($before !== $after) {
                    $this->securityLogger->log(SecurityEventType::RolePermissionsChanged, null, [
                        'role' => $role->name,
                        'added' => array_values(array_diff($after, $before)),
                        'removed' => array_values(array_diff($before, $after)),
                        'by' => $actor->ulid,
                    ]);
                }
            }
        });

        if ($activeChanged) {
            $this->synchronizer->syncRoleHolders($role);
        }

        return $role->refresh()->load('permissions');
    }

    public function delete(Role $role): void
    {
        $this->assertCustom($role);

        $inUse = RoleAssignment::query()->effective()->where('role_id', $role->id)->exists();

        if ($inUse) {
            throw new BusinessRuleException('Revoke this role from all users before deleting it.', 'ROLE_IN_USE', 409);
        }

        $role->delete();
    }

    private function assertCustom(Role $role): void
    {
        if ($role->is_system) {
            throw new BusinessRuleException('Built-in roles cannot be changed. Create a custom role instead.', 'SYSTEM_ROLE_READ_ONLY', 403);
        }

        if ($role->institution_id !== $this->currentInstitution->id()) {
            throw new BusinessRuleException('The requested resource was not found.', 'NOT_FOUND', 404);
        }
    }
}
