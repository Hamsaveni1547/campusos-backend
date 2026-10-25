<?php

namespace App\Modules\Identity\Actions\Users;

use App\Models\User;
use App\Modules\Identity\Actions\Roles\PrivilegeGuard;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Notifications\MfaChangedNotification;
use App\Modules\Identity\Services\PasswordPolicy;
use App\Modules\Identity\Services\RecoveryCodeService;
use App\Modules\Identity\Services\TrustedDeviceService;
use App\Shared\Audit\SecurityEventType;
use App\Shared\Audit\SecurityLogger;
use App\Shared\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Administrator actions on another user's credentials: unlock, password reset, MFA reset.
 */
class AdminSecurityActions
{
    public function __construct(
        private PasswordPolicy $passwordPolicy,
        private PrivilegeGuard $privilegeGuard,
        private RecoveryCodeService $recoveryCodes,
        private TrustedDeviceService $trustedDevices,
        private SecurityLogger $securityLogger,
        private CreateUser $createUser,
    ) {}

    public function unlock(User $user): User
    {
        $user->forceFill(['locked_until' => null, 'failed_login_attempts' => 0])->save();
        $this->securityLogger->log(SecurityEventType::AccountUnlocked, $user);

        return $user;
    }

    /**
     * Either email a reset link (default) or set a temporary password returned once.
     *
     * @return array{temporary_password: string|null}
     */
    public function resetPassword(User $user, User $actor, bool $generateTemporary): array
    {
        $this->guardTarget($user, $actor);

        if ($user->status === UserStatus::Invited) {
            $this->createUser->sendInvitation($user);
            $this->securityLogger->log(SecurityEventType::PasswordResetByAdmin, $user, ['mode' => 'invitation_resent']);

            return ['temporary_password' => null];
        }

        if (! $generateTemporary) {
            Password::broker('users')->sendResetLink(['email' => $user->email]);
            $this->securityLogger->log(SecurityEventType::PasswordResetByAdmin, $user, ['mode' => 'link']);

            return ['temporary_password' => null];
        }

        $temporary = Str::password(10, symbols: false).'aA1!'.Str::password(4, letters: false, symbols: false);

        DB::transaction(function () use ($user, $temporary): void {
            $this->passwordPolicy->apply($user, $temporary, mustChangeOnNextLogin: true);
            $user->tokens()->delete();
        });

        $this->securityLogger->log(SecurityEventType::PasswordResetByAdmin, $user, ['mode' => 'temporary_password']);

        return ['temporary_password' => $temporary];
    }

    /**
     * Remove all second factors (lost phone). The user re-enrols on next sign-in if required.
     */
    public function resetMfa(User $user, User $actor): void
    {
        $this->guardTarget($user, $actor);

        DB::transaction(function () use ($user): void {
            $user->mfaMethods()->delete();
            $this->recoveryCodes->clear($user);
            $this->trustedDevices->revokeAll($user);
            $user->tokens()->delete();
        });

        $this->securityLogger->log(SecurityEventType::MfaMethodRemoved, $user, ['by_admin' => true, 'all' => true]);
        $user->notify(new MfaChangedNotification(['change' => 'Two-step verification was reset by an administrator']));
    }

    private function guardTarget(User $user, User $actor): void
    {
        if ($actor->is($user)) {
            throw new BusinessRuleException('Use your own account settings to change your password or two-step verification.', 'SELF_ADMIN_ACTION', 403);
        }

        if ($user->is_platform_admin && ! $actor->is_platform_admin) {
            throw new BusinessRuleException('Platform administrators can only be managed at platform level.', 'PLATFORM_ADMIN_PROTECTED', 403);
        }

        // Resetting credentials of someone more privileged would allow taking over their account.
        $this->privilegeGuard->assertHoldsAll(
            $actor,
            $user->getAllPermissions()->pluck('name')->all(),
            'You cannot reset credentials of a user who has permissions you do not have.',
        );
    }
}
