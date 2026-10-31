<?php

namespace App\Modules\Admissions;

use App\Modules\Admissions\Console\ExpireAdmissionOffers;
use App\Modules\Admissions\Notifications\ApplicationStatusNotification;
use App\Modules\Communication\Support\NotificationTemplateRegistry;
use App\Shared\Settings\SettingsRegistry;
use Illuminate\Support\ServiceProvider;

class AdmissionsServiceProvider extends ServiceProvider
{
    public function boot(SettingsRegistry $settings, NotificationTemplateRegistry $templates): void
    {
        $settings->register('admissions', 'Admissions', [
            'application_no_format' => [
                'type' => 'string',
                'default' => '{INST}/APP/{YYYY}/{SEQ:5}',
                'rules' => ['string', 'max:40', 'regex:/\{SEQ(:\d+)?\}/'],
                'label' => 'Application number format ({INST}, {YYYY}, {YY}, {SEQ:5})',
            ],
            'register_no_format' => [
                'type' => 'string',
                'default' => '{INST}{YY}{PROG}{SEQ:3}',
                'rules' => ['string', 'max:40', 'regex:/\{SEQ(:\d+)?\}/'],
                'label' => 'Register number format ({INST}, {YY}, {PROG}, {SEQ:3}); numbered per programme and year',
            ],
        ]);

        $templates->register(ApplicationStatusNotification::class);

        if ($this->app->runningInConsole()) {
            $this->commands([ExpireAdmissionOffers::class]);
        }
    }
}
