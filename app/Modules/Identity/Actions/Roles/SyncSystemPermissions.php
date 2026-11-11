<?php

namespace App\Modules\Identity\Actions\Roles;

use App\Modules\Identity\Authorization\SystemRole;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Shared\Authorization\PermissionRegistry;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Upserts every registered permission and the built-in roles with their permissions.
 * Safe to run on every deploy.
 */
class SyncSystemPermissions
{
    public function __construct(
        private PermissionRegistry $registry,
        private PermissionRegistrar $registrar,
    ) {}

    /**
     * @return array{permissions: int, removed_permissions: int, roles: int}
     */
    public function handle(): array
    {
        $definitions = $this->registry->all();

        return DB::transaction(function () use ($definitions): array {
            $existing = Permission::query()->pluck('name')->all();
            $new = array_values(array_diff(array_keys($definitions), $existing));

            foreach ($definitions as $name => $definition) {
                Permission::query()->updateOrCreate(
                    ['name' => $name, 'guard_name' => 'web'],
                    ['module' => $definition['module'], 'description' => $definition['description'], 'is_sensitive' => $definition['sensitive']],
                );
            }

            $removed = Permission::query()->whereNotIn('name', array_keys($definitions))->delete();

            $this->registrar->forgetCachedPermissions();

            foreach (SystemRole::cases() as $systemRole) {
                /** @var Role $role */
                $role = Role::query()->firstOrNew(['name' => $systemRole->value, 'guard_name' => 'web', 'institution_id' => null]);

                $defaults = $this->registry->expand($systemRole->permissionPatterns());

                // A role the platform admin edited keeps its name, description and grants;
                // it only receives permissions that are new in this release (by its defaults).
                if ($role->exists && $role->is_customised) {
                    $role->givePermissionTo(array_values(array_intersect($defaults, $new)));

                    continue;
                }

                $role->forceFill([
                    'display_name' => $systemRole->displayName(),
                    'description' => $systemRole->description(),
                    'is_system' => true,
                    'is_active' => true,
                    'is_department_scoped' => $systemRole->isDepartmentScoped(),
                ])->save();

                $role->syncPermissions($defaults);
            }

            $this->registrar->forgetCachedPermissions();

            return ['permissions' => count($definitions), 'removed_permissions' => $removed, 'roles' => count(SystemRole::cases())];
        });
    }
}
