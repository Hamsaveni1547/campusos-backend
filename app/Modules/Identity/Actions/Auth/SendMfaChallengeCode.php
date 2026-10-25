<?php

namespace App\Modules\Identity\Actions\Auth;

use App\Modules\Identity\Enums\MfaMethodType;
use App\Modules\Identity\Enums\OneTimeCodePurpose;
use App\Modules\Identity\Services\OneTimeCodeService;
use App\Shared\Exceptions\BusinessRuleException;
use Illuminate\Support\Carbon;

/**
 * Sends the sign-in code by email or SMS for an open challenge.
 */
class SendMfaChallengeCode
{
    public function __construct(
        private CompleteMfaChallenge $challenges,
        private OneTimeCodeService $codes,
    ) {}

    /**
     * @return array{destination: string, expires_at: Carbon}
     */
    public function handle(string $challengeToken, MfaMethodType $type): array
    {
        if (! $type->usesOneTimeCode()) {
            throw new BusinessRuleException('Authenticator app codes are generated on your device.', 'MFA_METHOD_NO_CODE');
        }

        $challenge = $this->challenges->openChallenge($challengeToken);
        $user = $challenge->user;

        if (! $user->mfaMethods()->confirmed()->where('type', $type)->exists()) {
            throw new BusinessRuleException('This verification method is not set up for the account.', 'MFA_METHOD_NOT_ENABLED');
        }

        $code = $this->codes->issue($user, OneTimeCodePurpose::LoginChallenge, $challenge->institution_id);

        return [
            'destination' => self::mask((string) $code->destination),
            'expires_at' => $code->expires_at,
        ];
    }

    public static function mask(string $destination): string
    {
        if (str_contains($destination, '@')) {
            [$name, $domain] = explode('@', $destination, 2);

            return mb_substr($name, 0, 2).str_repeat('*', max(1, mb_strlen($name) - 2)).'@'.$domain;
        }

        return str_repeat('*', max(0, mb_strlen($destination) - 4)).mb_substr($destination, -4);
    }
}
