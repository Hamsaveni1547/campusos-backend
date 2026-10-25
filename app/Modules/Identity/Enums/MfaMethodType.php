<?php

namespace App\Modules\Identity\Enums;

enum MfaMethodType: string
{
    case Totp = 'totp';
    case Email = 'email';

    public function usesOneTimeCode(): bool
    {
        return $this === self::Email;
    }
}
