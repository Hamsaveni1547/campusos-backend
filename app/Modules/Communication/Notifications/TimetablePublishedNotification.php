<?php

namespace App\Modules\Communication\Notifications;

use App\Modules\Communication\Enums\NotificationCategory;

class TimetablePublishedNotification extends EventNotification
{
    public static function templateKey(): string
    {
        return 'timetable.published';
    }

    public static function category(): NotificationCategory
    {
        return NotificationCategory::Academic;
    }

    public static function description(): string
    {
        return "Sent to students and their teachers when a section's timetable is published or changed.";
    }

    public static function placeholders(): array
    {
        return ['app_name', 'name', 'section', 'term'];
    }

    public static function defaultTemplates(): array
    {
        return [
            'database' => ['subject' => 'Timetable published: {{ section }}', 'body' => 'The {{ term }} timetable for {{ section }} is published. Check your classes for the week.'],
            'mail' => ['subject' => '{{ section }} timetable is published', 'body' => "Hello {{ name }},\n\nThe {{ term }} timetable for {{ section }} has been published or changed.\n\nSign in to see your classes."],
        ];
    }
}
