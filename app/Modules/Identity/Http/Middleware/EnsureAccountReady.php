<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Models\User;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Services\MfaRequirement;
use App\Modules\Identity\Services\PasswordPolicy;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Tenancy\CurrentInstitution;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks normal API use until the account is ready: email verified, password
 * current, and two-step verification set up when the role requires it.
 * The endpoints that fix each condition are outside this middleware.
 */
class EnsureAccountReady
{
    public function __construct(
        private CurrentInstitution $currentInstitution,
        private PasswordPolicy $passwordPolicy,
        private MfaRequirement $mfaRequirement,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $requirement = self::pendingRequirement($user, $this->currentInstitution->id(), $this->passwordPolicy, $this->mfaRequirement);

        if ($requirement !== null) {
            throw new BusinessRuleException($requirement['message'], $requirement['code'], 403);
        }

        return $next($request);
    }

    /**
     * @return array{code: string, message: string}|null
     */
    public static function pendingRequirement(User $user, ?int $institutionId, PasswordPolicy $passwordPolicy, MfaRequirement $mfaRequirement): ?array
    {
        if ($user->status === UserStatus::PendingVerification) {
            return ['code' => 'EMAIL_VERIFICATION_REQUIRED', 'message' => 'Verify your email address to continue.'];
        }

        if ($user->must_change_password || $passwordPolicy->isExpired($user, $institutionId)) {
            return ['code' => 'PASSWORD_CHANGE_REQUIRED', 'message' => 'You need to set a new password to continue.'];
        }

        if ($mfaRequirement->needsEnrollment($user, $institutionId)) {
            return ['code' => 'MFA_ENROLLMENT_REQUIRED', 'message' => 'Your role requires two-step verification. Set it up to continue.'];
        }

        return null;
    }
}
