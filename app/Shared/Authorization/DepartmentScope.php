<?php

namespace App\Shared\Authorization;

use App\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Models\RoleAssignment;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Database\Eloquent\Builder;

/**
 * Departments a user may act on for a permission: all of them (null) when any role giving that
 * permission is institution-wide, otherwise only the departments those roles are limited to (e.g. a HOD).
 */
final class DepartmentScope
{
    /**
     * @param  string|list<string>  $permissions  any of
     * @param  bool  $homeDepartment  for per-department roles (HOD, Faculty) granted without a department,
     *                                use the person's own department from their staff record
     * @return list<int>|null null = no limit
     */
    public static function for(User $user, string|array $permissions, bool $homeDepartment = false): ?array
    {
        $assignments = RoleAssignment::query()
            ->with('role:id,is_department_scoped')
            ->effective()
            ->where('institution_id', app(CurrentInstitution::class)->id())
            ->where('user_id', $user->id)
            ->whereHas('role.permissions', fn (Builder $query) => $query->whereIn('name', (array) $permissions))
            ->get(['id', 'role_id', 'department_id']);

        if ($assignments->isEmpty()) {
            return null;
        }

        $home = null;
        $departments = [];

        foreach ($assignments as $assignment) {
            if ($assignment->department_id !== null) {
                $departments[] = (int) $assignment->department_id;

                continue;
            }

            if (! $homeDepartment || ! $assignment->role->is_department_scoped) {
                return null;
            }

            $home ??= Employee::query()->where('user_id', $user->id)->value('department_id');

            if ($home === null) {
                return null;
            }
            $departments[] = (int) $home;
        }

        return array_values(array_unique($departments));
    }

    /**
     * True when the department is inside the user's scope for the permission.
     *
     * @param  string|list<string>  $permissions
     */
    public static function allows(User $user, string|array $permissions, ?int $departmentId): bool
    {
        $departments = self::for($user, $permissions);

        return $departments === null || in_array($departmentId, $departments, true);
    }

    /**
     * True when every role giving the user these permissions is the named role (e.g. only Faculty).
     *
     * @param  string|list<string>  $permissions
     */
    public static function onlyRole(User $user, string|array $permissions, string $roleName): bool
    {
        $roles = RoleAssignment::query()
            ->effective()
            ->where('institution_id', app(CurrentInstitution::class)->id())
            ->where('user_id', $user->id)
            ->whereHas('role.permissions', fn (Builder $query) => $query->whereIn('name', (array) $permissions))
            ->with('role:id,name')
            ->get()
            ->pluck('role.name')
            ->unique();

        return $roles->isNotEmpty() && $roles->every(fn ($name): bool => $name === $roleName);
    }
}
