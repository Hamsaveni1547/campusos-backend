<?php

namespace App\Modules\Communication\Listeners;

use App\Modules\Communication\Models\NotificationDelivery;
use App\Modules\Communication\Notifications\CampusNotification;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Str;

/**
 * Records mail/SMS delivery outcomes for the delivery report.
 */
class RecordNotificationDelivery
{
    public function handle(NotificationSent|NotificationFailed $event): void
    {
        if ($event->channel === 'database') {
            return;
        }

        $failed = $event instanceof NotificationFailed;
        $error = null;

        if ($failed) {
            $exception = $event->data['exception'] ?? null;
            $error = $exception instanceof \Throwable ? Str::limit($exception->getMessage(), 1000) : 'Delivery failed.';
        }

        NotificationDelivery::query()->withoutGlobalScopes()->create([
            'institution_id' => $event->notification instanceof CampusNotification ? $event->notification->institutionId : null,
            'notification_id' => $event->notification->id,
            'notification_type' => $event->notification instanceof CampusNotification ? $event->notification::templateKey() : $event->notification::class,
            'channel' => $event->channel,
            'notifiable_type' => $event->notifiable::class,
            'notifiable_id' => $event->notifiable->getKey(),
            'status' => $failed ? 'failed' : 'sent',
            'error' => $error,
            'created_at' => now(),
        ]);
    }
}
