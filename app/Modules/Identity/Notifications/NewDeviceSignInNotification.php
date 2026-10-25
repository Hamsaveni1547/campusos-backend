<?php

namespace App\Modules\Identity\Notifications;

class NewDeviceSignInNotification extends SecurityAlertNotification
{
    public static function templateKey(): string
    {
        return 'identity.security_alert.new_device';
    }

    public static function description(): string
    {
        return 'Sent when the account signs in from a device or IP address not seen before.';
    }

    public static function defaultTemplates(): array
    {
        return [
            'database' => [
                'subject' => 'New sign-in to your account',
                'body' => 'Your account signed in on {{ time }} from {{ device }} ({{ ip_address }}).',
            ],
            'mail' => [
                'subject' => 'New sign-in to your {{ app_name }} account',
                'body' => "Hello {{ name }},\n\nYour account signed in on {{ time }} from {{ device }} (IP {{ ip_address }}).\n\nIf this was you, no action is needed. If not, change your password now and contact your administrator.",
            ],
        ];
    }
}
