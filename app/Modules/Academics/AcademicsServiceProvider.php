<?php

namespace App\Modules\Academics;

use App\Shared\Settings\SettingsRegistry;
use Illuminate\Support\ServiceProvider;

class AcademicsServiceProvider extends ServiceProvider
{
    public function boot(SettingsRegistry $settings): void
    {
        $settings->register('academics', 'Academics', [
            'max_weekly_hours' => [
                'type' => 'int',
                'default' => 18,
                'rules' => ['integer', 'min:0', 'max:60'],
                'label' => 'Maximum weekly teaching hours per teacher (0 = no warning)',
            ],
        ]);
    }
}
