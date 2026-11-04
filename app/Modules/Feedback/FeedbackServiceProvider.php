<?php

namespace App\Modules\Feedback;

use App\Shared\Settings\SettingsRegistry;
use Illuminate\Support\ServiceProvider;

class FeedbackServiceProvider extends ServiceProvider
{
    public function boot(SettingsRegistry $settings): void
    {
        $settings->register('feedback', 'Feedback', [
            'min_responses' => ['type' => 'int', 'default' => 5, 'rules' => ['integer', 'min:1', 'max:100'], 'label' => 'Responses needed before results are shown (keeps answers anonymous)'],
        ]);
    }
}
