<?php

namespace App\Modules\Identity\Enums;

enum MfaMethodType: string
{
    case Totp = 'totp';
    case Email = 'email';
    case Sms = 'sms';

    public function usesOneTimeCode(): bool
    {
        return $this !== self::Totp;
    }

    public function channel(): OneTimeCodeChannel
    {
        return match ($this) {
            self::Sms => OneTimeCodeChannel::Sms,
            default => OneTimeCodeChannel::Email,
        };
    }
}
