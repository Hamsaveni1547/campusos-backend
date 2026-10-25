<?php

namespace App\Modules\Communication\Channels;

use App\Modules\Communication\Contracts\SmsGateway;
use App\Modules\Communication\Notifications\CampusNotification;
use Illuminate\Notifications\Notification;

class SmsChannel
{
    public function __construct(private SmsGateway $gateway) {}

    public function send(object $notifiable, Notification $notification): void
    {
        $mobile = $notifiable->routeNotificationFor('sms', $notification) ?? ($notifiable->mobile ?? null);

        if (blank($mobile) || ! $notification instanceof CampusNotification) {
            return;
        }

        $this->gateway->send((string) $mobile, $notification->toSms($notifiable)->text);
    }
}
