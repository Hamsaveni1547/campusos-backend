<?php

namespace App\Modules\Identity\Actions\Auth;

use App\Models\User;
use App\Modules\Identity\Enums\OneTimeCodePurpose;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Services\OneTimeCodeService;
use App\Shared\Audit\SecurityEventType;
use App\Shared\Audit\SecurityLogger;
use App\Shared\Exceptions\BusinessRuleException;

class VerifyEmail
{
    public function __construct(
        private OneTimeCodeService $codes,
        private SecurityLogger $securityLogger,
    ) {}

    public function send(User $user): void
    {
        if ($user->email_verified_at !== null) {
            throw new BusinessRuleException('Your email is already verified.', 'EMAIL_ALREADY_VERIFIED', 409);
        }

        $this->codes->issue($user, OneTimeCodePurpose::EmailVerification, $user->defaultInstitution()?->id);
    }

    public function verify(User $user, string $code): User
    {
        if ($user->email_verified_at !== null) {
            return $user;
        }

        $institutionId = $user->defaultInstitution()?->id;

        if (! $this->codes->verify($user, OneTimeCodePurpose::EmailVerification, $code, $institutionId)) {
            throw new BusinessRuleException('The code is incorrect or has expired.', 'INVALID_CODE', 422);
        }

        $user->forceFill([
            'email_verified_at' => now(),
            'status' => $user->status === UserStatus::PendingVerification ? UserStatus::Active : $user->status,
        ])->save();

        $this->securityLogger->log(SecurityEventType::EmailVerified, $user, institutionId: $institutionId);

        return $user;
    }
}
