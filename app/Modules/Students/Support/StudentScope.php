<?php

namespace App\Modules\Students\Support;

use App\Models\User;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Students\Authorization\StudentsPermissions;
use App\Modules\Students\Models\Student;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which students a staff member may see: everyone when the view permission comes from an
 * institution-wide role, otherwise only students of the departments their roles are limited to.
 */
final class StudentScope
{
    /**
     * @return array<int, int>|null null = no limit
     */
    public static function departmentsFor(User $user, int $institutionId): ?array
    {
        $assignments = RoleAssignment::query()
            ->effective()
            ->where('institution_id', $institutionId)
            ->where('user_id', $user->id)
            ->whereHas('role.permissions', fn (Builder $query) => $query->where('name', StudentsPermissions::RecordsView))
            ->get(['department_id']);

        if ($assignments->contains(fn (RoleAssignment $assignment): bool => $assignment->department_id === null)) {
            return null;
        }

        return $assignments->pluck('department_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
    }

    /**
     * @param  Builder<Student>  $query
     * @return Builder<Student>
     */
    public static function apply(Builder $query, User $user, int $institutionId): Builder
    {
        $departments = self::departmentsFor($user, $institutionId);

        if ($departments === null) {
            return $query;
        }

        return $departments === [] ? $query->whereRaw('1 = 0') : $query->inDepartments($departments);
    }
}
