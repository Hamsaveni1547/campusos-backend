<?php

namespace App\Modules\Identity\Actions\Users;

use App\Models\User;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\PersonalAccessToken;
use App\Shared\Audit\SecurityEventType;
use App\Shared\Audit\SecurityLogger;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Support\Facades\DB;

/**
 * Activates or deactivates a user's access to the current institution.
 * Deactivation keeps all history; the account is fully deactivated only when
 * no active institution remains.
 */
class ChangeMembershipStatus
{
    public function __construct(
        private CurrentInstitution $currentInstitution,
        private SecurityLogger $securityLogger,
    ) {}

    public function deactivate(User $user, User $actor, string $reason): User
    {
        $institution = $this->currentInstitution->require();

        if ($actor->is($user)) {
            throw new BusinessRuleException('You cannot deactivate your own account.', 'SELF_DEACTIVATION', 403);
        }

        if ($user->is_platform_admin) {
            throw new BusinessRuleException('Platform administrators can only be managed at platform level.', 'PLATFORM_ADMIN_PROTECTED', 403);
        }

        DB::transaction(function () use ($user, $institution, $reason): void {
            $user->institutions()->updateExistingPivot($institution->id, ['status' => 'inactive']);

            PersonalAccessToken::query()
                ->where('tokenable_type', $user->getMorphClass())
                ->where('tokenable_id', $user->id)
                ->where(fn ($query) => $query->where('institution_id', $institution->id)->orWhereNull('institution_id'))
                ->delete();

            $hasOtherActive = $user->institutions()->wherePivot('status', 'active')->exists();

            if (! $hasOtherActive) {
                $user->forceFill([
                    'status' => UserStatus::Inactive,
                    'deactivated_at' => now(),
                    'deactivation_reason' => $reason,
                ])->save();

                $user->tokens()->delete();
            }
        });

        $this->securityLogger->log(SecurityEventType::UserDeactivated, $user, ['reason' => $reason]);

        return $user->refresh();
    }

    public function activate(User $user, User $actor): User
    {
        $institution = $this->currentInstitution->require();

        if ($actor->is($user)) {
            throw new BusinessRuleException('You cannot change the status of your own account.', 'SELF_DEACTIVATION', 403);
        }

        DB::transaction(function () use ($user, $institution): void {
            $user->institutions()->updateExistingPivot($institution->id, ['status' => 'active']);

            if ($user->status === UserStatus::Inactive) {
                $user->forceFill([
                    'status' => UserStatus::Active,
                    'deactivated_at' => null,
                    'deactivation_reason' => null,
                ])->save();
            }
        });

        $this->securityLogger->log(SecurityEventType::UserActivated, $user);

        return $user->refresh();
    }
}
