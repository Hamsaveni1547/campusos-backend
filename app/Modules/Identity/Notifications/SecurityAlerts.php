<?php

namespace App\Modules\Identity\Notifications;

/**
 * Concrete security alert notifications.
 */
final class SecurityAlerts
{
    /**
     * @return array<int, class-string<SecurityAlertNotification>>
     */
    public static function all(): array
    {
        return [
            NewDeviceSignInNotification::class,
            PasswordChangedNotification::class,
            AccountLockedNotification::class,
            MfaChangedNotification::class,
        ];
    }
}
