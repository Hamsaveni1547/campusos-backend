<?php

namespace App\Modules\Communication;

use App\Modules\Communication\Channels\CampusDatabaseChannel;
use App\Modules\Communication\Listeners\RecordNotificationDelivery;
use App\Modules\Communication\Support\NotificationPreferences;
use App\Modules\Communication\Support\NotificationTemplateRegistry;
use App\Modules\Communication\Support\TemplateRenderer;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class CommunicationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NotificationTemplateRegistry::class);
        $this->app->singleton(TemplateRenderer::class);
        $this->app->singleton(NotificationPreferences::class);

        $this->app->bind(DatabaseChannel::class, CampusDatabaseChannel::class);
    }

    public function boot(): void
    {
        Event::listen(NotificationSent::class, RecordNotificationDelivery::class);
        Event::listen(NotificationFailed::class, RecordNotificationDelivery::class);
    }
}
