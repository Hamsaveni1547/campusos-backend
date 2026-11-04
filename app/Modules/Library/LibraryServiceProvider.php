<?php

namespace App\Modules\Library;

use App\Shared\Settings\SettingsRegistry;
use Illuminate\Support\ServiceProvider;

class LibraryServiceProvider extends ServiceProvider
{
    public function boot(SettingsRegistry $settings): void
    {
        $settings->register('library', 'Library', [
            'student_limit' => ['type' => 'int', 'default' => 4, 'rules' => ['integer', 'min:1', 'max:50'], 'label' => 'Books a student can borrow at once'],
            'staff_limit' => ['type' => 'int', 'default' => 8, 'rules' => ['integer', 'min:1', 'max:50'], 'label' => 'Books a staff member can borrow at once'],
            'student_days' => ['type' => 'int', 'default' => 14, 'rules' => ['integer', 'min:1', 'max:365'], 'label' => 'Loan period for students (days)'],
            'staff_days' => ['type' => 'int', 'default' => 30, 'rules' => ['integer', 'min:1', 'max:365'], 'label' => 'Loan period for staff (days)'],
            'max_renewals' => ['type' => 'int', 'default' => 2, 'rules' => ['integer', 'min:0', 'max:10'], 'label' => 'Times a loan can be renewed'],
            'fine_per_day' => ['type' => 'int', 'default' => 2, 'rules' => ['integer', 'min:0', 'max:1000'], 'label' => 'Overdue fine per day (₹)'],
        ]);
    }
}
