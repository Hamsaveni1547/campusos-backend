<?php

namespace App\Modules\Communication\Notifications;

use App\Modules\Communication\Enums\NotificationCategory;

/**
 * Results of an exam cycle were published.
 */
class ResultsPublishedNotification extends EventNotification
{
    public static function templateKey(): string
    {
        return 'exams.results_published';
    }

    public static function category(): NotificationCategory
    {
        return NotificationCategory::Examination;
    }

    public static function description(): string
    {
        return 'Sent to each student when the results of an exam cycle are published.';
    }

    public static function placeholders(): array
    {
        return ['app_name', 'name', 'cycle', 'sgpa', 'cgpa'];
    }

    public static function defaultTemplates(): array
    {
        return [
            'database' => ['subject' => 'Results published: {{ cycle }}', 'body' => 'Your SGPA is {{ sgpa }} and CGPA {{ cgpa }}. Open your results for course-wise grades.'],
            'mail' => ['subject' => '{{ cycle }} results are out', 'body' => "Hello {{ name }},\n\nResults of {{ cycle }} are published. Your SGPA is {{ sgpa }} and CGPA {{ cgpa }}.\n\nSign in to see course-wise grades or ask for revaluation."],
        ];
    }
}
