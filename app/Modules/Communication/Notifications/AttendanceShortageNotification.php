<?php

namespace App\Modules\Communication\Notifications;

use App\Modules\Communication\Enums\NotificationCategory;

class AttendanceShortageNotification extends EventNotification
{
    public static function templateKey(): string
    {
        return 'attendance.shortage';
    }

    public static function category(): NotificationCategory
    {
        return NotificationCategory::Academic;
    }

    public static function description(): string
    {
        return 'Weekly alert to students (and their mentors) whose attendance is below the required level.';
    }

    public static function placeholders(): array
    {
        return ['app_name', 'name', 'student', 'courses'];
    }

    public static function defaultTemplates(): array
    {
        return [
            'database' => ['subject' => 'Attendance below the limit', 'body' => '{{ student }} is short of attendance in: {{ courses }}.'],
            'mail' => ['subject' => 'Attendance alert for {{ student }}', 'body' => "Hello {{ name }},\n\n{{ student }} is below the required attendance in: {{ courses }}.\n\nPlease attend every class; talk to the mentor if there is a problem."],
        ];
    }
}
