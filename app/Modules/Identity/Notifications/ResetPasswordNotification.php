<?php

namespace App\Modules\Identity\Notifications;

use App\Modules\Communication\Enums\NotificationCategory;
use App\Modules\Communication\Enums\NotificationChannel;
use App\Modules\Communication\Notifications\CampusNotification;
use App\Modules\Identity\Support\FrontendUrl;

class ResetPasswordNotification extends CampusNotification
{
    public function __construct(public string $token)
    {
        parent::__construct();
    }

    public static function templateKey(): string
    {
        return 'identity.password_reset';
    }

    public static function category(): NotificationCategory
    {
        return NotificationCategory::Security;
    }

    public static function description(): string
    {
        return 'Sent when a user asks to reset a forgotten password.';
    }

    public static function defaultTemplates(): array
    {
        return [
            'mail' => [
                'subject' => 'Reset your {{ app_name }} password',
                'body' => "Hello {{ name }},\n\nWe received a request to reset your password. The link below is valid for {{ expires_minutes }} minutes.\n\nIf you did not ask for this, you can ignore this email. Your password will not change.",
            ],
        ];
    }

    public static function placeholders(): array
    {
        return ['app_name', 'name', 'expires_minutes'];
    }

    protected function forcedChannels(): array
    {
        return [NotificationChannel::Mail];
    }

    protected function variables(object $notifiable): array
    {
        return [
            'name' => $notifiable->name,
            'expires_minutes' => (int) config('auth.passwords.users.expire'),
        ];
    }

    protected function action(object $notifiable): ?array
    {
        return [
            'text' => 'Reset password',
            'url' => FrontendUrl::to('reset_password', ['token' => $this->token, 'email' => $notifiable->email]),
        ];
    }
}
