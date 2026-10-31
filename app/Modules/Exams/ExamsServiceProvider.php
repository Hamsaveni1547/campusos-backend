<?php

namespace App\Modules\Exams;

use App\Shared\Settings\SettingsRegistry;
use Illuminate\Support\ServiceProvider;

class ExamsServiceProvider extends ServiceProvider
{
    public function boot(SettingsRegistry $settings): void
    {
        $settings->register('exams', 'Examinations', [
            'external_pass_percent' => ['type' => 'int', 'default' => 35, 'rules' => ['integer', 'min:0', 'max:100'], 'label' => 'Minimum % in the end-term exam'],
            'overall_pass_percent' => ['type' => 'int', 'default' => 40, 'rules' => ['integer', 'min:0', 'max:100'], 'label' => 'Minimum % overall (internal + exam)'],
            'grades' => [
                'type' => 'array',
                'default' => [
                    ['grade' => 'O', 'min' => 90, 'points' => 10],
                    ['grade' => 'A+', 'min' => 80, 'points' => 9],
                    ['grade' => 'A', 'min' => 70, 'points' => 8],
                    ['grade' => 'B+', 'min' => 60, 'points' => 7],
                    ['grade' => 'B', 'min' => 50, 'points' => 6],
                    ['grade' => 'C', 'min' => 45, 'points' => 5],
                    ['grade' => 'P', 'min' => 40, 'points' => 4],
                ],
                'rules' => ['array', 'min:1'],
                'item_rules' => ['array:grade,min,points', 'required_array_keys:grade,min,points'],
                'label' => 'Grade bands for passing students (grade, minimum %, grade points); failing is F',
            ],
        ]);
    }
}
