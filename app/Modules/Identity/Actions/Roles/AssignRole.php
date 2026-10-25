<?php

namespace App\Modules\Identity\Actions\Roles;

use App\Models\User;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Identity\Services\RoleSynchronizer;
use App\Shared\Audit\SecurityEventType;
use App\Shared\Audit\SecurityLogger;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Grants a role to a user in the current institution, optionally limited to a
 * department and a validity period.
 */
class AssignRole
{
    public function __construct(
        private CurrentInstitution $currentInstitution,
        private RoleSynchronizer $synchronizer,
        private PrivilegeGuard $privilegeGuard,
        private SecurityLogger $securityLogger,
    ) {}

    /**
     * @param  User|null  $actor  null when the system assigns (e.g. self-registration)
     */
    public function handle(
        User $user,
        Role $role,
        ?User $actor,
        ?int $departmentId = null,
        ?Carbon $validFrom = null,
        ?Carbon $validUntil = null,
        ?string $reason = null,
    ): RoleAssignment {
        $institution = $this->currentInstitution->require();

        if ($role->institution_id !== null && $role->institution_id !== $institution->id) {
            throw new BusinessRuleException('This role does not belong to the current institution.', 'ROLE_NOT_AVAILABLE', 422);
        }

        if (! $role->is_active) {
            throw new BusinessRuleException('This role is inactive and cannot be assigned.', 'ROLE_INACTIVE', 422);
        }

        if ($actor !== null) {
            $this->privilegeGuard->assertCanGrant($actor, $user, $role);
        }

        if ($departmentId !== null && ! $role->is_department_scoped) {
            throw new BusinessRuleException('This role is not limited by department.', 'ROLE_NOT_DEPARTMENT_SCOPED', 422);
        }

        if ($validFrom !== null && $validUntil !== null && $validUntil->lte($validFrom)) {
            throw new BusinessRuleException('The end date must be after the start date.', 'INVALID_VALIDITY_PERIOD', 422);
        }

        if (! $user->belongsToInstitution($institution->id)) {
            throw new BusinessRuleException('The user is not a member of this institution.', 'USER_NOT_MEMBER', 422);
        }

        $duplicate = RoleAssignment::query()
            ->effective()
            ->where('user_id', $user->id)
            ->where('role_id', $role->id)
            ->where('department_id', $departmentId)
            ->exists();

        if ($duplicate) {
            throw new BusinessRuleException('The user already holds this role.', 'ROLE_ALREADY_ASSIGNED', 409);
        }

        $assignment = DB::transaction(function () use ($user, $role, $actor, $departmentId, $validFrom, $validUntil, $reason, $institution): RoleAssignment {
            $assignment = RoleAssignment::query()->create([
                'institution_id' => $institution->id,
                'user_id' => $user->id,
                'role_id' => $role->id,
                'department_id' => $departmentId,
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'reason' => $reason,
                'assigned_by' => $actor?->id,
            ]);

            $this->synchronizer->sync($user, $institution->id);

            return $assignment;
        });

        $this->securityLogger->log(SecurityEventType::RoleAssigned, $user, [
            'role' => $role->name,
            'department_id' => $departmentId,
            'valid_until' => $validUntil?->toIso8601String(),
        ]);

        return $assignment->load('role');
    }
}
