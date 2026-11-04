<?php

namespace App\Modules\Communication;

use App\Modules\Communication\Channels\CampusDatabaseChannel;
use App\Modules\Communication\Console\RunAlertsCommand;
use App\Modules\Communication\Listeners\RecordNotificationDelivery;
use App\Modules\Communication\Notifications\AnnouncementNotification;
use App\Modules\Communication\Notifications\AttendanceShortageNotification;
use App\Modules\Communication\Notifications\FeeReminderNotification;
use App\Modules\Communication\Notifications\ResultsPublishedNotification;
use App\Modules\Communication\Notifications\TimetablePublishedNotification;
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

    public function boot(NotificationTemplateRegistry $templates): void
    {
        foreach ([AnnouncementNotification::class, ResultsPublishedNotification::class, TimetablePublishedNotification::class, AttendanceShortageNotification::class, FeeReminderNotification::class] as $notification) {
            $templates->register($notification);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([RunAlertsCommand::class]);
        }

        Event::listen(NotificationSent::class, RecordNotificationDelivery::class);
        Event::listen(NotificationFailed::class, RecordNotificationDelivery::class);
    }
}
