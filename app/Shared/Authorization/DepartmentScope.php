<?php

namespace App\Shared\Authorization;

use App\Models\User;
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
     * @return list<int>|null null = no limit
     */
    public static function for(User $user, string|array $permissions): ?array
    {
        $assignments = RoleAssignment::query()
            ->effective()
            ->where('institution_id', app(CurrentInstitution::class)->id())
            ->where('user_id', $user->id)
            ->whereHas('role.permissions', fn (Builder $query) => $query->whereIn('name', (array) $permissions))
            ->get(['department_id']);

        if ($assignments->isEmpty() || $assignments->contains(fn (RoleAssignment $a): bool => $a->department_id === null)) {
            return null;
        }

        return $assignments->pluck('department_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
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
}
