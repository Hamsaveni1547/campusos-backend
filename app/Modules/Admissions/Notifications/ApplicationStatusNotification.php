<?php

namespace App\Modules\Admissions\Notifications;

use App\Modules\Admissions\Models\Application;
use App\Modules\Communication\Enums\NotificationCategory;
use App\Modules\Communication\Notifications\CampusNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Tells the applicant that their application moved to a new status.
 */
class ApplicationStatusNotification extends CampusNotification implements ShouldQueue
{
    public function __construct(
        public string $applicationNo,
        public string $programme,
        public string $status,
        public ?string $remarks = null,
        public ?string $offerExpires = null,
    ) {
        parent::__construct();
    }

    public static function fromApplication(Application $application): self
    {
        return new self(
            (string) $application->application_no,
            (string) $application->seat->programme->name,
            $application->status->label(),
            $application->remarks,
            $application->offer_expires_at?->format('d M Y'),
        );
    }

    public static function templateKey(): string
    {
        return 'admissions.application_status';
    }

    public static function category(): NotificationCategory
    {
        return NotificationCategory::Admissions;
    }

    public static function description(): string
    {
        return 'Sent to the applicant whenever the application status changes.';
    }

    public static function placeholders(): array
    {
        return ['app_name', 'name', 'application_no', 'programme', 'status', 'remarks', 'offer_expires'];
    }

    public static function defaultTemplates(): array
    {
        return [
            'database' => [
                'subject' => 'Application {{ application_no }}: {{ status }}',
                'body' => 'Your application for {{ programme }} is now "{{ status }}". {{ remarks }}',
            ],
            'mail' => [
                'subject' => 'Your {{ app_name }} application {{ application_no }} is now {{ status }}',
                'body' => "Hello {{ name }},\n\nYour application {{ application_no }} for {{ programme }} is now: {{ status }}.\n\n{{ remarks }}\n\nSign in to see the details and what to do next.",
            ],
        ];
    }

    protected function variables(object $notifiable): array
    {
        return [
            'name' => $notifiable->name ?? '',
            'application_no' => $this->applicationNo,
            'programme' => $this->programme,
            'status' => $this->status,
            'remarks' => $this->remarks ?? ($this->offerExpires ? 'Accept the offer before '.$this->offerExpires.'.' : ''),
            'offer_expires' => $this->offerExpires,
        ];
    }
}
