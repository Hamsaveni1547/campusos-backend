<?php

namespace App\Modules\Identity\Notifications;

class MfaChangedNotification extends SecurityAlertNotification
{
    public static function templateKey(): string
    {
        return 'identity.security_alert.mfa_changed';
    }

    public static function description(): string
    {
        return 'Sent when two-step verification is turned on or off, or recovery codes are regenerated.';
    }

    public static function placeholders(): array
    {
        return array_merge(parent::placeholders(), ['change']);
    }

    public static function defaultTemplates(): array
    {
        return [
            'database' => [
                'subject' => 'Two-step verification changed',
                'body' => '{{ change }} on {{ time }}.',
            ],
            'mail' => [
                'subject' => 'Two-step verification changed on your {{ app_name }} account',
                'body' => "Hello {{ name }},\n\n{{ change }} on {{ time }} (IP {{ ip_address }}).\n\nIf you did not do this, contact your administrator immediately.",
            ],
        ];
    }
}
