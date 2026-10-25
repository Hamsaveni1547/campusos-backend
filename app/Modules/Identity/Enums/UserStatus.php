<?php

namespace App\Modules\Identity\Enums;

enum UserStatus: string
{
    /** Registered but email not yet verified (self-registered applicants). */
    case PendingVerification = 'pending_verification';

    /** Created by an administrator; the user has not set a password yet. */
    case Invited = 'invited';

    case Active = 'active';

    case Inactive = 'inactive';

    public function canLogIn(): bool
    {
        return in_array($this, [self::Active, self::PendingVerification], true);
    }
}
