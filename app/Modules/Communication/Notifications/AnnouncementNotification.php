<?php

namespace App\Modules\Communication\Notifications;

use App\Modules\Communication\Enums\NotificationCategory;
use App\Modules\Communication\Models\Announcement;

/**
 * Tells each recipient that a new announcement was published.
 */
class AnnouncementNotification extends CampusNotification
{
    public function __construct(public string $announcementId, public string $title, public string $excerpt, public string $from, public string $categoryValue)
    {
        parent::__construct();
    }

    public static function fromAnnouncement(Announcement $announcement): self
    {
        return new self($announcement->ulid, $announcement->title, str($announcement->body)->squish()->limit(160)->toString(), (string) $announcement->author?->name, $announcement->category);
    }

    public static function templateKey(): string
    {
        return 'communication.announcement';
    }

    public static function category(): NotificationCategory
    {
        return NotificationCategory::General;
    }

    public function databaseColumns(): array
    {
        return [...parent::databaseColumns(), 'category' => (NotificationCategory::tryFrom($this->categoryValue) ?? NotificationCategory::General)->value];
    }

    public static function description(): string
    {
        return 'Sent to everyone in the audience when an announcement is published.';
    }

    public static function placeholders(): array
    {
        return ['app_name', 'name', 'title', 'excerpt', 'from'];
    }

    public static function defaultTemplates(): array
    {
        return [
            'database' => ['subject' => '{{ title }}', 'body' => '{{ excerpt }}'],
            'mail' => ['subject' => 'Announcement: {{ title }}', 'body' => "Hello {{ name }},\n\n{{ from }} published an announcement: {{ title }}.\n\n{{ excerpt }}\n\nSign in to read it in full."],
        ];
    }

    protected function variables(object $notifiable): array
    {
        return ['name' => $notifiable->name ?? '', 'title' => $this->title, 'excerpt' => $this->excerpt, 'from' => $this->from];
    }

    protected function action(object $notifiable): ?array
    {
        return ['url' => rtrim((string) config('campusos.frontend_url', config('app.url')), '/').'/announcements/'.$this->announcementId, 'text' => 'Read announcement'];
    }
}
