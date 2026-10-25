<?php

namespace App\Modules\Identity\Notifications;

use App\Modules\Communication\Enums\NotificationCategory;
use App\Modules\Communication\Notifications\CampusNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Security alerts: new device sign-in, password changed, account locked, MFA changes.
 * One template per alert type, keyed "identity.security_alert.{type}".
 */
abstract class SecurityAlertNotification extends CampusNotification implements ShouldQueue
{
    /**
     * @param  array<string, string|int|null>  $details
     */
    public function __construct(public array $details = [])
    {
        parent::__construct();
    }

    public static function category(): NotificationCategory
    {
        return NotificationCategory::Security;
    }

    public static function placeholders(): array
    {
        return ['app_name', 'name', 'time', 'ip_address', 'device'];
    }

    protected function variables(object $notifiable): array
    {
        return array_merge([
            'name' => $notifiable->name ?? '',
            'time' => now()->timezone((string) ($notifiable->timezone ?? 'Asia/Kolkata'))->format('d M Y, h:i A T'),
            'ip_address' => null,
            'device' => null,
        ], $this->details);
    }
}
