<?php

namespace App\Modules\Identity\Enums;

enum OneTimeCodePurpose: string
{
    case LoginChallenge = 'login_challenge';
    case MfaEnrollment = 'mfa_enrollment';
    case EmailVerification = 'email_verification';
    case MobileVerification = 'mobile_verification';

    public function label(): string
    {
        return match ($this) {
            self::LoginChallenge => 'sign-in verification',
            self::MfaEnrollment => 'two-step verification setup',
            self::EmailVerification => 'email verification',
            self::MobileVerification => 'mobile number verification',
        };
    }
}
