<?php

namespace App\Modules\Attendance;

use App\Shared\Settings\SettingsRegistry;
use Illuminate\Support\ServiceProvider;

class AttendanceServiceProvider extends ServiceProvider
{
    public function boot(SettingsRegistry $settings): void
    {
        $settings->register('attendance', 'Attendance', [
            'threshold' => ['type' => 'int', 'default' => 75, 'rules' => ['integer', 'min:0', 'max:100'], 'label' => 'Minimum attendance % per course'],
            'condonation_min' => ['type' => 'int', 'default' => 65, 'rules' => ['integer', 'min:0', 'max:100'], 'label' => 'Lowest % that can be condoned'],
            'edit_window_hours' => ['type' => 'int', 'default' => 48, 'rules' => ['integer', 'min:0', 'max:720'], 'label' => 'Hours after the day when teachers can still change attendance (0 = always)'],
            'late_counts_present' => ['type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'label' => 'Late counts as attended'],
            'on_duty_counts_present' => ['type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'label' => 'On duty (OD) counts as attended'],
            'medical_counts_present' => ['type' => 'bool', 'default' => false, 'rules' => ['boolean'], 'label' => 'Medical leave counts as attended'],
        ]);
    }
}
