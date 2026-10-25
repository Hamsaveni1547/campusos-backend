<?php

namespace App\Modules\Identity\Notifications;

use App\Modules\Communication\Enums\NotificationCategory;
use App\Modules\Communication\Enums\NotificationChannel;
use App\Modules\Communication\Notifications\CampusNotification;
use App\Modules\Identity\Enums\OneTimeCodePurpose;

/**
 * Emails a one-time code. Sent immediately (not queued).
 */
class OneTimeCodeNotification extends CampusNotification
{
    public function __construct(
        public string $code,
        public OneTimeCodePurpose $purpose,
        public int $expiresMinutes,
    ) {
        parent::__construct();
    }

    public static function templateKey(): string
    {
        return 'identity.one_time_code';
    }

    public static function category(): NotificationCategory
    {
        return NotificationCategory::Security;
    }

    public static function description(): string
    {
        return 'One-time code for sign-in, two-step setup and email verification.';
    }

    public static function defaultTemplates(): array
    {
        return [
            'mail' => [
                'subject' => 'Your {{ app_name }} verification code: {{ code }}',
                'body' => "Hello {{ name }},\n\nYour code for {{ purpose }} is {{ code }}. It expires in {{ expires_minutes }} minutes.\n\nNever share this code with anyone. {{ app_name }} staff will never ask for it.",
            ],
        ];
    }

    public static function placeholders(): array
    {
        return ['app_name', 'name', 'code', 'purpose', 'expires_minutes'];
    }

    protected function forcedChannels(): array
    {
        return [NotificationChannel::Mail];
    }

    protected function variables(object $notifiable): array
    {
        return [
            'name' => $notifiable->name ?? '',
            'code' => $this->code,
            'purpose' => $this->purpose->label(),
            'expires_minutes' => $this->expiresMinutes,
        ];
    }
}
