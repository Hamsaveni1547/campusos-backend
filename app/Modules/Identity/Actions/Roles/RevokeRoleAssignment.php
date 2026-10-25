<?php

namespace App\Modules\Identity\Actions\Roles;

use App\Models\User;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Identity\Services\RoleSynchronizer;
use App\Shared\Audit\SecurityEventType;
use App\Shared\Audit\SecurityLogger;
use App\Shared\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

class RevokeRoleAssignment
{
    public function __construct(
        private RoleSynchronizer $synchronizer,
        private PrivilegeGuard $privilegeGuard,
        private SecurityLogger $securityLogger,
    ) {}

    public function handle(RoleAssignment $assignment, User $actor, ?string $reason = null): RoleAssignment
    {
        if ($assignment->revoked_at !== null) {
            throw new BusinessRuleException('This role assignment is already revoked.', 'ASSIGNMENT_ALREADY_REVOKED', 409);
        }

        $this->privilegeGuard->assertCanRevoke($actor, $assignment->user, $assignment->role);

        DB::transaction(function () use ($assignment, $actor, $reason): void {
            $assignment->update([
                'revoked_at' => now(),
                'revoked_by' => $actor->id,
                'revocation_reason' => $reason,
            ]);

            $this->synchronizer->sync($assignment->user, $assignment->institution_id);
        });

        $this->securityLogger->log(SecurityEventType::RoleRevoked, $assignment->user, [
            'role' => $assignment->role->name,
            'reason' => $reason,
        ]);

        return $assignment;
    }
}
