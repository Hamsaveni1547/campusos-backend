<?php

namespace App\Modules\Communication;

use App\Modules\Communication\Channels\CampusDatabaseChannel;
use App\Modules\Communication\Channels\SmsChannel;
use App\Modules\Communication\Contracts\SmsGateway;
use App\Modules\Communication\Gateways\LogSmsGateway;
use App\Modules\Communication\Listeners\RecordNotificationDelivery;
use App\Modules\Communication\Support\NotificationPreferences;
use App\Modules\Communication\Support\NotificationTemplateRegistry;
use App\Modules\Communication\Support\TemplateRenderer;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class CommunicationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NotificationTemplateRegistry::class);
        $this->app->singleton(TemplateRenderer::class);
        $this->app->singleton(NotificationPreferences::class);

        $this->app->singleton(SmsGateway::class, function (): SmsGateway {
            return match (config('campusos.sms.driver')) {
                'log' => new LogSmsGateway,
                default => throw new InvalidArgumentException('Unsupported SMS driver ['.config('campusos.sms.driver').']. Configure CAMPUSOS_SMS_DRIVER.'),
            };
        });

        $this->app->bind(DatabaseChannel::class, CampusDatabaseChannel::class);
    }

    public function boot(): void
    {
        $this->app->make(ChannelManager::class)->extend('sms', fn ($app) => $app->make(SmsChannel::class));

        Event::listen(NotificationSent::class, RecordNotificationDelivery::class);
        Event::listen(NotificationFailed::class, RecordNotificationDelivery::class);
    }
}
