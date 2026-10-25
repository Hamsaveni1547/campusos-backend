<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\RoleAssignment;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Keeps spatie's model_has_roles in line with effective role_assignments,
 * so `$user->can()` reflects validity dates and revocations.
 */
class RoleSynchronizer
{
    public function __construct(private PermissionRegistrar $registrar) {}

    public function sync(User $user, int $institutionId): void
    {
        $effectiveRoleIds = RoleAssignment::query()
            ->withoutGlobalScopes()
            ->effective()
            ->where('institution_id', $institutionId)
            ->where('user_id', $user->id)
            ->whereHas('role', fn ($query) => $query->where('is_active', true))
            ->pluck('role_id')
            ->unique()
            ->values()
            ->all();

        $table = config('permission.table_names.model_has_roles');
        $teamKey = config('permission.column_names.team_foreign_key');
        $morphKey = config('permission.column_names.model_morph_key');

        DB::transaction(function () use ($table, $teamKey, $morphKey, $user, $institutionId, $effectiveRoleIds): void {
            DB::table($table)
                ->where($teamKey, $institutionId)
                ->where($morphKey, $user->id)
                ->where('model_type', $user->getMorphClass())
                ->whereNotIn('role_id', $effectiveRoleIds === [] ? [0] : $effectiveRoleIds)
                ->delete();

            $existing = DB::table($table)
                ->where($teamKey, $institutionId)
                ->where($morphKey, $user->id)
                ->where('model_type', $user->getMorphClass())
                ->pluck('role_id')
                ->all();

            foreach (array_diff($effectiveRoleIds, $existing) as $roleId) {
                DB::table($table)->insert([
                    'role_id' => $roleId,
                    'model_type' => $user->getMorphClass(),
                    $morphKey => $user->id,
                    $teamKey => $institutionId,
                ]);
            }
        });

        $user->unsetRelation('roles')->unsetRelation('permissions');
        $this->registrar->forgetCachedPermissions();
    }

    /**
     * Re-sync every holder of a role (after deactivating/activating a role).
     */
    public function syncRoleHolders(Role $role): void
    {
        RoleAssignment::query()
            ->withoutGlobalScopes()
            ->where('role_id', $role->id)
            ->with('user')
            ->get()
            ->unique(fn (RoleAssignment $assignment): string => $assignment->user_id.'|'.$assignment->institution_id)
            ->each(fn (RoleAssignment $assignment) => $this->sync($assignment->user, $assignment->institution_id));
    }
}
