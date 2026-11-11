<?php

namespace App\Modules\Students\Support;

use App\Models\User;
use App\Modules\Identity\Authorization\SystemRole;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Students\Authorization\StudentsPermissions;
use App\Modules\Students\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
        if (self::teachesOnly($user, $institutionId)) {
            return self::taughtBy($query, $user);
        }

        $departments = self::departmentsFor($user, $institutionId);

        if ($departments === null) {
            return $query;
        }

        return $departments === [] ? $query->whereRaw('1 = 0') : $query->inDepartments($departments);
    }

    /**
     * True when the only roles giving this user the student list are teaching roles (Faculty):
     * they see the students registered in the classes they teach, not a whole department.
     */
    public static function teachesOnly(User $user, int $institutionId): bool
    {
        $roles = RoleAssignment::query()
            ->effective()
            ->where('institution_id', $institutionId)
            ->where('user_id', $user->id)
            ->whereHas('role.permissions', fn (Builder $query) => $query->where('name', StudentsPermissions::RecordsView))
            ->with('role:id,name')
            ->get()
            ->pluck('role.name')
            ->unique();

        return $roles->isNotEmpty() && $roles->every(fn ($name): bool => $name === SystemRole::Faculty->value);
    }

    /**
     * Students registered (this or any term) for a course offering in a section the teacher is allocated to.
     *
     * @param  Builder<Student>  $query
     * @return Builder<Student>
     */
    private static function taughtBy(Builder $query, User $user): Builder
    {
        return $query->whereExists(fn ($sub) => $sub->select(DB::raw(1))
            ->from('term_registrations')
            ->join('course_registrations', 'course_registrations.term_registration_id', '=', 'term_registrations.id')
            ->join('offering_sections', function ($join): void {
                $join->on('offering_sections.course_offering_id', '=', 'course_registrations.course_offering_id')
                    ->on('offering_sections.section_id', '=', 'term_registrations.section_id');
            })
            ->join('faculty_allocations', 'faculty_allocations.offering_section_id', '=', 'offering_sections.id')
            ->join('employees', 'employees.id', '=', 'faculty_allocations.employee_id')
            ->whereColumn('term_registrations.student_id', 'students.id')
            ->where('term_registrations.status', '!=', 'rejected')
            ->where('employees.user_id', $user->id));
    }
}
