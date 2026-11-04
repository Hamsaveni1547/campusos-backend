<?php

namespace App\Modules\Communication\Notifications;

use App\Modules\Communication\Enums\NotificationCategory;

class FeeReminderNotification extends EventNotification
{
    public static function templateKey(): string
    {
        return 'finance.fee_reminder';
    }

    public static function category(): NotificationCategory
    {
        return NotificationCategory::Finance;
    }

    public static function description(): string
    {
        return 'Sent before a fee falls due and again when it is overdue.';
    }

    public static function placeholders(): array
    {
        return ['app_name', 'name', 'title', 'amount', 'due_on', 'state'];
    }

    public static function defaultTemplates(): array
    {
        return [
            'database' => ['subject' => 'Fee {{ state }}: {{ title }}', 'body' => 'Rs. {{ amount }} for {{ title }} is {{ state }} (due {{ due_on }}).'],
            'mail' => ['subject' => 'Fee {{ state }}: {{ title }}', 'body' => "Hello {{ name }},\n\nRs. {{ amount }} for {{ title }} is {{ state }} (due {{ due_on }}).\n\nPay online from your fees page or at the accounts counter."],
        ];
    }
}
