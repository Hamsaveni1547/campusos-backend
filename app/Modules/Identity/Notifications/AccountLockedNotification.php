<?php

namespace App\Modules\Identity\Notifications;

class AccountLockedNotification extends SecurityAlertNotification
{
    public static function templateKey(): string
    {
        return 'identity.security_alert.account_locked';
    }

    public static function description(): string
    {
        return 'Sent when the account is locked after too many failed sign-in attempts.';
    }

    public static function placeholders(): array
    {
        return array_merge(parent::placeholders(), ['locked_minutes']);
    }

    public static function defaultTemplates(): array
    {
        return [
            'mail' => [
                'subject' => 'Your {{ app_name }} account is temporarily locked',
                'body' => "Hello {{ name }},\n\nYour account was locked on {{ time }} after several failed sign-in attempts (last attempt from IP {{ ip_address }}). You can try again in {{ locked_minutes }} minutes, or reset your password.\n\nIf these attempts were not yours, reset your password and inform your administrator.",
            ],
        ];
    }
}
