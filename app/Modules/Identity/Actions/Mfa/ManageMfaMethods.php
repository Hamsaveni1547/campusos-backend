<?php

namespace App\Modules\Identity\Actions\Mfa;

use App\Models\User;
use App\Modules\Identity\Actions\Auth\SendMfaChallengeCode;
use App\Modules\Identity\Enums\MfaMethodType;
use App\Modules\Identity\Enums\OneTimeCodeChannel;
use App\Modules\Identity\Enums\OneTimeCodePurpose;
use App\Modules\Identity\Models\MfaMethod;
use App\Modules\Identity\Notifications\MfaChangedNotification;
use App\Modules\Identity\Services\MfaRequirement;
use App\Modules\Identity\Services\OneTimeCodeService;
use App\Modules\Identity\Services\RecoveryCodeService;
use App\Modules\Identity\Services\TotpService;
use App\Modules\Identity\Services\TrustedDeviceService;
use App\Shared\Audit\SecurityEventType;
use App\Shared\Audit\SecurityLogger;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Http\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * Self-service two-step verification: enrol, confirm, remove, recovery codes.
 */
class ManageMfaMethods
{
    public function __construct(
        private TotpService $totp,
        private OneTimeCodeService $codes,
        private RecoveryCodeService $recoveryCodes,
        private TrustedDeviceService $trustedDevices,
        private MfaRequirement $requirement,
        private SecurityLogger $securityLogger,
        private RequestContext $context,
    ) {}

    /**
     * @return array{secret: string, otpauth_uri: string, qr_svg: string}
     */
    public function startTotp(User $user): array
    {
        $this->assertNotConfirmed($user, MfaMethodType::Totp);

        $secret = $this->totp->generateSecret();

        MfaMethod::query()->updateOrCreate(
            ['user_id' => $user->id, 'type' => MfaMethodType::Totp],
            ['secret' => $secret, 'confirmed_at' => null, 'is_primary' => false, 'last_used_timestep' => null],
        );

        $uri = $this->totp->provisioningUri($user, $secret);

        return ['secret' => $secret, 'otpauth_uri' => $uri, 'qr_svg' => $this->totp->qrCodeSvg($uri)];
    }

    /**
     * @return array{method: MfaMethod, recovery_codes: array<int, string>|null}
     */
    public function confirmTotp(User $user, string $code, ?int $institutionId): array
    {
        $method = $user->mfaMethods()->where('type', MfaMethodType::Totp)->whereNull('confirmed_at')->first();

        if ($method === null) {
            throw new BusinessRuleException('Start the authenticator setup first.', 'MFA_SETUP_NOT_STARTED', 409);
        }

        if (! $this->totp->verify($method, $code)) {
            throw new BusinessRuleException('The code is incorrect. Check the time on your phone and try again.', 'INVALID_CODE', 422);
        }

        return $this->confirm($user, $method, $institutionId);
    }

    /**
     * Send an enrolment code for email or SMS. For SMS a new mobile number may be given.
     */
    public function startCodeMethod(User $user, MfaMethodType $type, ?string $mobile, ?int $institutionId): string
    {
        if (! $type->usesOneTimeCode()) {
            throw new BusinessRuleException('Use the authenticator setup for this method.', 'MFA_METHOD_INVALID');
        }

        $this->assertNotConfirmed($user, $type);

        $destination = $type === MfaMethodType::Sms ? ($mobile ?? $user->mobile) : $user->email;

        if (blank($destination)) {
            throw new BusinessRuleException('Enter a mobile number to receive codes by SMS.', 'MOBILE_REQUIRED');
        }

        MfaMethod::query()->updateOrCreate(
            ['user_id' => $user->id, 'type' => $type],
            ['secret' => null, 'confirmed_at' => null, 'is_primary' => false],
        );

        $code = $this->codes->issue($user, OneTimeCodePurpose::MfaEnrollment, $type->channel(), $institutionId, $destination);

        return SendMfaChallengeCode::mask((string) $code->destination);
    }

    /**
     * @return array{method: MfaMethod, recovery_codes: array<int, string>|null}
     */
    public function confirmCodeMethod(User $user, MfaMethodType $type, string $code, ?int $institutionId): array
    {
        $method = $user->mfaMethods()->where('type', $type)->whereNull('confirmed_at')->first();

        if ($method === null) {
            throw new BusinessRuleException('Request a code first.', 'MFA_SETUP_NOT_STARTED', 409);
        }

        $channel = $type->channel();
        $destination = $this->codes->latestDestination($user, OneTimeCodePurpose::MfaEnrollment, $channel);

        if (! $this->codes->verify($user, OneTimeCodePurpose::MfaEnrollment, $channel, $code, $institutionId)) {
            throw new BusinessRuleException('The code is incorrect or has expired.', 'INVALID_CODE', 422);
        }

        if ($channel === OneTimeCodeChannel::Sms) {
            $user->forceFill(['mobile' => $destination, 'mobile_verified_at' => now()])->save();
        } elseif ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $this->confirm($user, $method, $institutionId);
    }

    public function remove(User $user, MfaMethod $method, ?int $institutionId): void
    {
        if ($method->user_id !== $user->id) {
            throw new BusinessRuleException('Method not found.', 'NOT_FOUND', 404);
        }

        $remaining = $user->mfaMethods()->confirmed()->whereKeyNot($method->id)->count();

        if ($method->isConfirmed() && $remaining === 0 && $this->requirement->isRequired($user, $institutionId)) {
            throw new BusinessRuleException('Your role requires two-step verification. Add another method before removing this one.', 'MFA_REQUIRED_BY_ROLE', 409);
        }

        DB::transaction(function () use ($user, $method, $remaining): void {
            $wasPrimary = $method->is_primary;
            $method->delete();

            if ($remaining === 0) {
                $this->recoveryCodes->clear($user);
                $this->trustedDevices->revokeAll($user);

                return;
            }

            if ($wasPrimary) {
                $user->mfaMethods()->confirmed()->oldest('confirmed_at')->first()?->forceFill(['is_primary' => true])->save();
            }
        });

        if ($method->isConfirmed()) {
            $this->securityLogger->log(SecurityEventType::MfaMethodRemoved, $user, ['type' => $method->type->value], institutionId: $institutionId);
            $user->notify(new MfaChangedNotification([
                'change' => ucfirst($method->type->value).' verification was removed',
                'ip_address' => $this->context->ip(),
            ]));
        }
    }

    public function makePrimary(User $user, MfaMethod $method): void
    {
        if ($method->user_id !== $user->id || ! $method->isConfirmed()) {
            throw new BusinessRuleException('Only a confirmed method can be primary.', 'MFA_METHOD_NOT_CONFIRMED', 409);
        }

        DB::transaction(function () use ($user, $method): void {
            $user->mfaMethods()->update(['is_primary' => false]);
            $method->forceFill(['is_primary' => true])->save();
        });
    }

    /**
     * @return array<int, string>
     */
    public function regenerateRecoveryCodes(User $user, ?int $institutionId): array
    {
        if (! $user->hasMfaEnabled()) {
            throw new BusinessRuleException('Turn on two-step verification first.', 'MFA_NOT_ENABLED', 409);
        }

        $codes = $this->recoveryCodes->regenerate($user);

        $this->securityLogger->log(SecurityEventType::RecoveryCodesRegenerated, $user, institutionId: $institutionId);
        $user->notify(new MfaChangedNotification([
            'change' => 'New recovery codes were generated and the old ones stopped working',
            'ip_address' => $this->context->ip(),
        ]));

        return $codes;
    }

    /**
     * @return array{method: MfaMethod, recovery_codes: array<int, string>|null}
     */
    private function confirm(User $user, MfaMethod $method, ?int $institutionId): array
    {
        $isFirst = ! $user->mfaMethods()->confirmed()->exists();

        $method->forceFill([
            'confirmed_at' => now(),
            'is_primary' => $isFirst,
        ])->save();

        $recoveryCodes = $this->recoveryCodes->remaining($user) === 0 ? $this->recoveryCodes->regenerate($user) : null;

        $this->securityLogger->log(SecurityEventType::MfaMethodAdded, $user, ['type' => $method->type->value], institutionId: $institutionId);
        $user->notify(new MfaChangedNotification([
            'change' => ucfirst($method->type->value).' verification was turned on',
            'ip_address' => $this->context->ip(),
        ]));

        return ['method' => $method, 'recovery_codes' => $recoveryCodes];
    }

    private function assertNotConfirmed(User $user, MfaMethodType $type): void
    {
        if ($user->mfaMethods()->confirmed()->where('type', $type)->exists()) {
            throw new BusinessRuleException('This method is already set up. Remove it first to set it up again.', 'MFA_ALREADY_ENABLED', 409);
        }
    }
}
