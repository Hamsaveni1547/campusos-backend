<?php

namespace App\Modules\Communication\Notifications;

use App\Models\User;
use App\Modules\Communication\Enums\NotificationCategory;
use App\Modules\Communication\Enums\NotificationChannel;
use App\Modules\Communication\Support\NotificationPreferences;
use App\Modules\Communication\Support\TemplateRenderer;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Base class for every CampusOS notification.
 *
 * Subclasses declare a template key, category, channels, default templates and
 * the variables used in them. Institutions can override the templates.
 */
abstract class CampusNotification extends Notification
{
    use Queueable;

    public ?int $institutionId;

    public function __construct()
    {
        $this->institutionId = app(CurrentInstitution::class)->id();
    }

    /**
     * Unique key, e.g. "identity.password_reset".
     */
    abstract public static function templateKey(): string;

    abstract public static function category(): NotificationCategory;

    /**
     * Human description for the template editor.
     */
    abstract public static function description(): string;

    /**
     * Built-in templates per channel. Mail body is plain paragraphs separated by blank lines.
     *
     * @return array<string, array{subject?: string, body: string}>
     */
    abstract public static function defaultTemplates(): array;

    /**
     * Placeholder names available in the templates.
     *
     * @return array<int, string>
     */
    abstract public static function placeholders(): array;

    /**
     * Values for the placeholders.
     *
     * @return array<string, string|int|float|null>
     */
    abstract protected function variables(object $notifiable): array;

    /**
     * Optional button for mail and a link for the in-app notification.
     *
     * @return array{url: string, text: string}|null
     */
    protected function action(object $notifiable): ?array
    {
        return null;
    }

    /**
     * Channels that ignore user preferences (e.g. a code the user just asked for).
     *
     * @return array<int, NotificationChannel>
     */
    protected function forcedChannels(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $available = array_map(fn (string $channel): NotificationChannel => NotificationChannel::from($channel), array_keys(static::defaultTemplates()));
        $forced = $this->forcedChannels();

        if ($forced !== []) {
            return array_map(fn (NotificationChannel $channel): string => $this->laravelChannel($channel), $forced);
        }

        $category = static::category();

        $channels = array_filter($available, function (NotificationChannel $channel) use ($notifiable, $category): bool {
            if ($category->isMandatory() || ! $notifiable instanceof User) {
                return true;
            }

            return app(NotificationPreferences::class)->allows($notifiable, $category, $channel);
        });

        return array_values(array_map(fn (NotificationChannel $channel): string => $this->laravelChannel($channel), $channels));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $rendered = $this->rendered(NotificationChannel::Mail, $notifiable);
        $message = (new MailMessage)->subject($rendered['subject'] ?? config('app.name'));

        $paragraphs = preg_split('/\R{2,}/', trim($rendered['body'])) ?: [];
        $action = $this->action($notifiable);

        foreach ($paragraphs as $paragraph) {
            $message->line(trim($paragraph));
        }

        if ($action !== null) {
            $message->action($action['text'], $action['url']);
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $templates = static::defaultTemplates();
        $channel = isset($templates[NotificationChannel::Database->value]) ? NotificationChannel::Database : NotificationChannel::Mail;
        $rendered = $this->rendered($channel, $notifiable);
        $action = $this->action($notifiable);

        return [
            'template_key' => static::templateKey(),
            'category' => static::category()->value,
            'title' => $rendered['subject'],
            'body' => $rendered['body'],
            'action_url' => $action['url'] ?? null,
            'action_text' => $action['text'] ?? null,
        ];
    }

    /**
     * Extra columns stored with in-app notifications.
     *
     * @return array<string, mixed>
     */
    public function databaseColumns(): array
    {
        return [
            'institution_id' => $this->institutionId,
            'category' => static::category()->value,
        ];
    }

    /**
     * @return array{subject: string|null, body: string}
     */
    protected function rendered(NotificationChannel $channel, object $notifiable): array
    {
        $defaults = static::defaultTemplates();
        $default = $defaults[$channel->value] ?? $defaults[NotificationChannel::Mail->value];

        return app(TemplateRenderer::class)->render(
            static::templateKey(),
            $channel,
            $default,
            array_merge(['app_name' => (string) config('app.name')], $this->variables($notifiable)),
            $this->institutionId,
        );
    }

    private function laravelChannel(NotificationChannel $channel): string
    {
        return $channel->value;
    }
}
