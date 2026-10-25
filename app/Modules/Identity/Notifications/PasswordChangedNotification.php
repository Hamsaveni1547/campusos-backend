<?php

namespace App\Modules\Identity\Notifications;

class PasswordChangedNotification extends SecurityAlertNotification
{
    public static function templateKey(): string
    {
        return 'identity.security_alert.password_changed';
    }

    public static function description(): string
    {
        return 'Sent after the account password is changed or reset.';
    }

    public static function defaultTemplates(): array
    {
        return [
            'database' => [
                'subject' => 'Your password was changed',
                'body' => 'Your password was changed on {{ time }}.',
            ],
            'mail' => [
                'subject' => 'Your {{ app_name }} password was changed',
                'body' => "Hello {{ name }},\n\nThe password for your account was changed on {{ time }} (IP {{ ip_address }}). You have been signed out of other devices.\n\nIf you did not do this, reset your password immediately and contact your administrator.",
            ],
        ];
    }
}
