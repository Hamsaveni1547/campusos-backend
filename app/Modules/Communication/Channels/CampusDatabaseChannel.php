<?php

namespace App\Modules\Communication\Channels;

use App\Modules\Communication\Notifications\CampusNotification;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * Stores institution_id and category alongside each in-app notification.
 */
class CampusDatabaseChannel extends DatabaseChannel
{
    /**
     * @return array<string, mixed>
     */
    protected function buildPayload($notifiable, Notification $notification)
    {
        $payload = parent::buildPayload($notifiable, $notification);

        if ($notification instanceof CampusNotification) {
            $payload = array_merge($payload, $notification->databaseColumns());
        }

        return $payload;
    }
}
