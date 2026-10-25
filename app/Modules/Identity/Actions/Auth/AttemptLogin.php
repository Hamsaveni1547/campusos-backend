<?php

namespace App\Modules\Identity\Actions\Auth;

use App\Models\User;
use App\Modules\Identity\Enums\MfaMethodType;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\MfaChallenge;
use App\Modules\Identity\Notifications\AccountLockedNotification;
use App\Modules\Identity\Services\LoginCompleter;
use App\Modules\Identity\Services\LoginResult;
use App\Modules\Identity\Services\TrustedDeviceService;
use App\Modules\Identity\Support\SecuritySettings;
use App\Modules\Institution\Models\Institution;
use App\Shared\Audit\SecurityEventType;
use App\Shared\Audit\SecurityLogger;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Http\RequestContext;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Step 1 of sign-in: check the password, apply lockout, and either issue a token
 * or open an MFA challenge.
 */
class AttemptLogin
{
    private const INVALID_MESSAGE = 'The sign-in details are incorrect.';

    public function __construct(
        private SecuritySettings $settings,
        private SecurityLogger $securityLogger,
        private TrustedDeviceService $trustedDevices,
        private LoginCompleter $completer,
        private RequestContext $context,
    ) {}

    public function handle(string $login, string $password, string $deviceName, ?string $trustedDeviceToken = null): LoginResult
    {
        $user = $this->findUser($login);

        if ($user === null) {
            // Spend the same time as a real check so response time does not reveal accounts.
            Hash::check($password, '$2y$12$'.str_repeat('a', 53));
            $this->securityLogger->log(SecurityEventType::LoginFailed, null, ['reason' => 'unknown_account'], Str::limit($login, 250, ''));

            throw new BusinessRuleException(self::INVALID_MESSAGE, 'INVALID_CREDENTIALS', 422);
        }

        $institution = $user->defaultInstitution();
        $institutionId = $institution?->id;

        if ($user->isLocked()) {
            $this->securityLogger->log(SecurityEventType::LoginBlocked, $user, ['reason' => 'locked'], institutionId: $institutionId);
            $minutes = (int) ceil(now()->diffInMinutes($user->locked_until, true));

            throw new BusinessRuleException(
                "This account is temporarily locked. Try again in {$minutes} minutes or reset your password.",
                'ACCOUNT_LOCKED',
                423,
                ['retry_after_minutes' => max($minutes, 1)],
            );
        }

        if (! Hash::check($password, $user->password)) {
            $this->recordFailure($user, $institutionId);

            throw new BusinessRuleException(self::INVALID_MESSAGE, 'INVALID_CREDENTIALS', 422);
        }

        $this->assertCanSignIn($user, $institution);

        $methods = $user->mfaMethods()->confirmed()->get();

        if ($methods->isNotEmpty() && ! $this->trustedDevices->check($user, $trustedDeviceToken)) {
            return $this->openChallenge($user, $institutionId, $deviceName, $methods->pluck('type')->all());
        }

        return $this->completer->complete($user, $deviceName, $institutionId);
    }

    private function findUser(string $login): ?User
    {
        $login = trim($login);

        return User::query()
            ->where(function ($query) use ($login): void {
                $query->where('email', mb_strtolower($login))
                    ->orWhere('username', $login)
                    ->orWhere('mobile', $login);
            })
            ->first();
    }

    private function recordFailure(User $user, ?int $institutionId): void
    {
        $maxAttempts = $this->settings->int('max_login_attempts', $institutionId);
        $attempts = $user->failed_login_attempts + 1;

        if ($attempts >= $maxAttempts) {
            $lockMinutes = $this->settings->int('lockout_minutes', $institutionId);

            $user->forceFill([
                'failed_login_attempts' => 0,
                'locked_until' => now()->addMinutes($lockMinutes),
            ])->save();

            $this->securityLogger->log(SecurityEventType::AccountLocked, $user, ['attempts' => $attempts, 'minutes' => $lockMinutes], institutionId: $institutionId);
            $user->notify(new AccountLockedNotification([
                'ip_address' => $this->context->ip(),
                'locked_minutes' => $lockMinutes,
            ]));

            return;
        }

        $user->forceFill(['failed_login_attempts' => $attempts])->save();
        $this->securityLogger->log(SecurityEventType::LoginFailed, $user, ['reason' => 'wrong_password', 'attempt' => $attempts], institutionId: $institutionId);
    }

    private function assertCanSignIn(User $user, ?Institution $institution): void
    {
        if (! $user->status->canLogIn()) {
            $this->securityLogger->log(SecurityEventType::LoginBlocked, $user, ['reason' => $user->status->value], institutionId: $institution?->id);

            throw new BusinessRuleException(
                $user->status === UserStatus::Invited
                    ? 'Your account is not set up yet. Use the link in your invitation email to set a password.'
                    : 'This account is deactivated. Contact your administrator.',
                $user->status === UserStatus::Invited ? 'ACCOUNT_NOT_SET_UP' : 'ACCOUNT_INACTIVE',
                403,
            );
        }

        if ($user->is_platform_admin) {
            return;
        }

        if ($institution === null) {
            throw new BusinessRuleException('Your account is not linked to any institution. Contact your administrator.', 'NO_INSTITUTION', 403);
        }

        if (! $institution->isActive()) {
            $this->securityLogger->log(SecurityEventType::LoginBlocked, $user, ['reason' => 'institution_suspended'], institutionId: $institution->id);

            throw new BusinessRuleException('Access for your institution is suspended. Contact your administrator.', 'INSTITUTION_SUSPENDED', 403);
        }
    }

    /**
     * @param  array<int, MfaMethodType>  $methods
     */
    private function openChallenge(User $user, ?int $institutionId, string $deviceName, array $methods): LoginResult
    {
        $token = Str::random(64);
        $expiresAt = now()->addMinutes((int) config('campusos.mfa.challenge_ttl_minutes'));

        MfaChallenge::query()->create([
            'user_id' => $user->id,
            'institution_id' => $institutionId,
            'token_hash' => hash('sha256', $token),
            'device_name' => Str::limit($deviceName, 250, ''),
            'ip_address' => $this->context->ip(),
            'user_agent' => $this->context->userAgent(),
            'attempts' => 0,
            'expires_at' => $expiresAt,
        ]);

        $this->securityLogger->log(SecurityEventType::MfaChallengeIssued, $user, [], institutionId: $institutionId);

        return LoginResult::challenge($user, $token, $expiresAt, $methods);
    }
}
