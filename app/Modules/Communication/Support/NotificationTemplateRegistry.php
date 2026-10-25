<?php

namespace App\Modules\Communication\Support;

use App\Modules\Communication\Notifications\CampusNotification;
use InvalidArgumentException;

/**
 * Knows every notification type that has editable templates.
 */
class NotificationTemplateRegistry
{
    /**
     * @var array<string, class-string<CampusNotification>>
     */
    private array $notifications = [];

    /**
     * @param  class-string<CampusNotification>  $notificationClass
     */
    public function register(string $notificationClass): void
    {
        $this->notifications[$notificationClass::templateKey()] = $notificationClass;
    }

    /**
     * @return array<string, class-string<CampusNotification>>
     */
    public function all(): array
    {
        ksort($this->notifications);

        return $this->notifications;
    }

    public function has(string $key): bool
    {
        return isset($this->notifications[$key]);
    }

    /**
     * @return class-string<CampusNotification>
     */
    public function get(string $key): string
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException("Unknown notification template [{$key}].");
        }

        return $this->notifications[$key];
    }
}
