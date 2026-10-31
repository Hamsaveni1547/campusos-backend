<?php

namespace App\Modules\Assessment;

use App\Shared\Settings\SettingsRegistry;
use Illuminate\Support\ServiceProvider;

class AssessmentServiceProvider extends ServiceProvider
{
    public function boot(SettingsRegistry $settings): void
    {
        $settings->register('assessment', 'Assessment', [
            'round_internal' => ['type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'label' => 'Round internal marks to whole numbers (half up)'],
            'co_target_percent' => ['type' => 'int', 'default' => 60, 'rules' => ['integer', 'min:1', 'max:100'], 'label' => 'Marks % a student needs to attain a course outcome'],
        ]);
    }
}
