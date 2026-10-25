<?php

namespace App\Modules\Identity\Actions\Auth;

use App\Modules\Identity\Enums\MfaMethodType;
use App\Modules\Identity\Enums\OneTimeCodePurpose;
use App\Modules\Identity\Models\MfaChallenge;
use App\Modules\Identity\Services\LoginCompleter;
use App\Modules\Identity\Services\LoginResult;
use App\Modules\Identity\Services\OneTimeCodeService;
use App\Modules\Identity\Services\RecoveryCodeService;
use App\Modules\Identity\Services\TotpService;
use App\Modules\Identity\Services\TrustedDeviceService;
use App\Shared\Audit\SecurityEventType;
use App\Shared\Audit\SecurityLogger;
use App\Shared\Exceptions\BusinessRuleException;

/**
 * Step 2 of sign-in: verify the second factor (authenticator, email/SMS code or recovery code).
 */
class CompleteMfaChallenge
{
    public function __construct(
        private TotpService $totp,
        private OneTimeCodeService $codes,
        private RecoveryCodeService $recoveryCodes,
        private TrustedDeviceService $trustedDevices,
        private LoginCompleter $completer,
        private SecurityLogger $securityLogger,
    ) {}

    public function handle(string $challengeToken, string $method, string $code, bool $rememberDevice = false): LoginResult
    {
        $challenge = $this->openChallenge($challengeToken);
        $user = $challenge->user;
        $institutionId = $challenge->institution_id;

        $verified = match ($method) {
            'recovery_code' => $this->recoveryCodes->consume($user, $code),
            default => $this->verifyMethod($challenge, MfaMethodType::from($method), $code),
        };

        if (! $verified) {
            $challenge->increment('attempts');
            $this->securityLogger->log(SecurityEventType::MfaChallengeFailed, $user, ['method' => $method], institutionId: $institutionId);

            $remaining = max(0, (int) config('campusos.mfa.challenge_max_attempts') - $challenge->attempts);

            throw new BusinessRuleException(
                $remaining > 0 ? 'The code is incorrect or has expired.' : 'Too many wrong codes. Please sign in again.',
                $remaining > 0 ? 'MFA_INVALID_CODE' : 'MFA_CHALLENGE_EXHAUSTED',
                422,
                ['attempts_remaining' => $remaining],
            );
        }

        $challenge->forceFill(['completed_at' => now()])->save();
        $this->securityLogger->log(SecurityEventType::MfaChallengePassed, $user, ['method' => $method], institutionId: $institutionId);

        if ($method === 'recovery_code') {
            $this->securityLogger->log(SecurityEventType::RecoveryCodeUsed, $user, ['remaining' => $this->recoveryCodes->remaining($user)], institutionId: $institutionId);
        }

        $trustedToken = null;

        if ($rememberDevice && $this->trustedDevices->isEnabled($institutionId)) {
            $trustedToken = $this->trustedDevices->trust($user, $challenge->device_name, $institutionId)['token'];
            $this->securityLogger->log(SecurityEventType::TrustedDeviceAdded, $user, ['device' => $challenge->device_name], institutionId: $institutionId);
        }

        return $this->completer->complete($user, (string) $challenge->device_name, $institutionId, $trustedToken, viaMfa: true);
    }

    public function openChallenge(string $challengeToken): MfaChallenge
    {
        $challenge = MfaChallenge::findByToken($challengeToken);

        if ($challenge === null || ! $challenge->isOpen()) {
            throw new BusinessRuleException('This verification step has expired. Please sign in again.', 'MFA_CHALLENGE_EXPIRED', 422);
        }

        return $challenge;
    }

    private function verifyMethod(MfaChallenge $challenge, MfaMethodType $type, string $code): bool
    {
        $method = $challenge->user->mfaMethods()->confirmed()->where('type', $type)->first();

        if ($method === null) {
            return false;
        }

        $verified = $type === MfaMethodType::Totp
            ? $this->totp->verify($method, $code)
            : $this->codes->verify($challenge->user, OneTimeCodePurpose::LoginChallenge, $type->channel(), $code, $challenge->institution_id);

        if ($verified && $type !== MfaMethodType::Totp) {
            $method->forceFill(['last_used_at' => now()])->save();
        }

        return $verified;
    }
}
