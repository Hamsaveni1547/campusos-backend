<?php

namespace App\Modules\Communication\Notifications;

/**
 * A notification about something that happened in a module, built from plain values and a link
 * to a frontend screen. Subclasses only declare the template key, category and texts.
 */
abstract class EventNotification extends CampusNotification
{
    /**
     * @param  array<string, string|int|float|null>  $values
     */
    public function __construct(public array $values, public ?string $path = null, public string $linkText = 'Open CampusOS')
    {
        parent::__construct();
    }

    protected function variables(object $notifiable): array
    {
        return ['name' => $notifiable->name ?? '', ...$this->values];
    }

    protected function action(object $notifiable): ?array
    {
        return $this->path === null ? null : ['url' => rtrim((string) config('campusos.frontend_url'), '/').$this->path, 'text' => $this->linkText];
    }
}
