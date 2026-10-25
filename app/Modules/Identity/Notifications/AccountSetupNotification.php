<?php

namespace App\Modules\Identity\Notifications;

use App\Modules\Communication\Enums\NotificationCategory;
use App\Modules\Communication\Enums\NotificationChannel;
use App\Modules\Communication\Notifications\CampusNotification;
use App\Modules\Identity\Support\FrontendUrl;
use App\Shared\Tenancy\CurrentInstitution;

/**
 * Invitation for an account created by an administrator: lets the user set a password.
 */
class AccountSetupNotification extends CampusNotification
{
    public function __construct(public string $token)
    {
        parent::__construct();
    }

    public static function templateKey(): string
    {
        return 'identity.account_setup';
    }

    public static function category(): NotificationCategory
    {
        return NotificationCategory::Account;
    }

    public static function description(): string
    {
        return 'Sent to a new user created by an administrator so they can set a password.';
    }

    public static function defaultTemplates(): array
    {
        return [
            'mail' => [
                'subject' => 'Your {{ institution_name }} account is ready',
                'body' => "Hello {{ name }},\n\nAn account has been created for you on {{ app_name }} for {{ institution_name }}. Your sign-in email is {{ email }}.\n\nUse the link below to set your password. It is valid for {{ expires_days }} days.",
            ],
        ];
    }

    public static function placeholders(): array
    {
        return ['app_name', 'name', 'email', 'institution_name', 'expires_days'];
    }

    protected function forcedChannels(): array
    {
        return [NotificationChannel::Mail];
    }

    protected function variables(object $notifiable): array
    {
        return [
            'name' => $notifiable->name,
            'email' => $notifiable->email,
            'institution_name' => app(CurrentInstitution::class)->get()->name ?? (string) config('app.name'),
            'expires_days' => intdiv((int) config('auth.passwords.invites.expire'), 1440),
        ];
    }

    protected function action(object $notifiable): ?array
    {
        return [
            'text' => 'Set your password',
            'url' => FrontendUrl::to('setup_account', ['token' => $this->token, 'email' => $notifiable->email]),
        ];
    }
}
