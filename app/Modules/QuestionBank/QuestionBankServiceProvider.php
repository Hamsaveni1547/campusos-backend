<?php

namespace App\Modules\QuestionBank;

use App\Shared\Settings\SettingsRegistry;
use Illuminate\Support\ServiceProvider;

class QuestionBankServiceProvider extends ServiceProvider
{
    public function boot(SettingsRegistry $settings): void
    {
        $settings->register('qbank', 'Question papers', [
            'release_minutes_before' => ['type' => 'int', 'default' => 30, 'rules' => ['integer', 'min:0', 'max:1440'], 'label' => 'Minutes before the exam when a sealed paper can be opened'],
        ]);
    }
}
