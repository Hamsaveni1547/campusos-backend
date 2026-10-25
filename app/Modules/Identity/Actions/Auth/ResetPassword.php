<?php

namespace App\Modules\Identity\Actions\Auth;

use App\Models\User;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Notifications\AccountSetupNotification;
use App\Modules\Identity\Notifications\PasswordChangedNotification;
use App\Modules\Identity\Services\PasswordPolicy;
use App\Shared\Audit\SecurityEventType;
use App\Shared\Audit\SecurityLogger;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Http\RequestContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/**
 * Forgot-password and invitation flows. Both use a signed token sent by email;
 * "setup" uses the longer-lived invites broker and activates the account.
 */
class ResetPassword
{
    public function __construct(
        private PasswordPolicy $passwordPolicy,
        private SecurityLogger $securityLogger,
        private RequestContext $context,
    ) {}

    /**
     * Always reports success so the response does not reveal which emails exist.
     */
    public function sendLink(string $email): void
    {
        $user = User::query()->where('email', mb_strtolower(trim($email)))->first();

        if ($user === null || in_array($user->status, [UserStatus::Inactive], true)) {
            return;
        }

        if ($user->status === UserStatus::Invited) {
            $token = Password::broker('invites')->createToken($user);
            $user->notify(new AccountSetupNotification($token));

            return;
        }

        $status = Password::broker('users')->sendResetLink(['email' => $user->email]);

        if ($status === Password::RESET_THROTTLED) {
            throw new BusinessRuleException('A reset link was sent recently. Please check your email or try again shortly.', 'RESET_THROTTLED', 429);
        }

        $this->securityLogger->log(SecurityEventType::PasswordResetRequested, $user, institutionId: $user->defaultInstitution()?->id);
    }

    /**
     * Validation hook so the password policy of the user's institution applies.
     */
    public function passwordRuleFor(string $email): \Illuminate\Validation\Rules\Password
    {
        $user = User::query()->where('email', mb_strtolower(trim($email)))->first();

        return $this->passwordPolicy->rule($user?->defaultInstitution()?->id);
    }

    public function reset(string $email, string $token, string $password, bool $isAccountSetup): User
    {
        $broker = Password::broker($isAccountSetup ? 'invites' : 'users');
        $user = $broker->getUser(['email' => mb_strtolower(trim($email))]);

        if (! $user instanceof User || ! $broker->tokenExists($user, $token)) {
            throw new BusinessRuleException('This link is invalid or has expired. Please request a new one.', 'INVALID_RESET_TOKEN', 422);
        }

        if ($user->status === UserStatus::Inactive) {
            throw new BusinessRuleException('This account is deactivated. Contact your administrator.', 'ACCOUNT_INACTIVE', 403);
        }

        $institutionId = $user->defaultInstitution()?->id;

        if (! $isAccountSetup) {
            $this->passwordPolicy->assertNotReused($user, $password, $institutionId);
        }

        DB::transaction(function () use ($user, $password, $broker, $isAccountSetup): void {
            $this->passwordPolicy->apply($user, $password);

            $user->forceFill([
                'locked_until' => null,
                'failed_login_attempts' => 0,
            ]);

            if ($isAccountSetup || $user->status === UserStatus::Invited) {
                $user->forceFill([
                    'status' => UserStatus::Active,
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ]);
            }

            $user->save();
            $user->tokens()->delete();
            $broker->deleteToken($user);
        });

        $this->securityLogger->log(SecurityEventType::PasswordResetCompleted, $user, ['setup' => $isAccountSetup], institutionId: $institutionId);

        if (! $isAccountSetup) {
            $user->notify(new PasswordChangedNotification(['ip_address' => $this->context->ip()]));
        }

        return $user;
    }
}
