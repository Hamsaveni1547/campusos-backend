<?php

namespace App\Modules\Communication\Support;

use App\Modules\Communication\Enums\NotificationChannel;
use App\Modules\Communication\Models\NotificationTemplate;

/**
 * Resolves a template (institution override → platform override → built-in default)
 * and replaces {{ placeholders }} with values.
 */
class TemplateRenderer
{
    /**
     * @param  array{subject?: string|null, body: string}  $default
     * @param  array<string, string|int|float|null>  $variables
     * @return array{subject: string|null, body: string}
     */
    public function render(string $templateKey, NotificationChannel $channel, array $default, array $variables, ?int $institutionId): array
    {
        $template = $this->find($templateKey, $channel, $institutionId);

        $subject = $template->subject ?? ($default['subject'] ?? null);
        $body = $template->body ?? $default['body'];

        return [
            'subject' => $subject === null ? null : $this->replace($subject, $variables),
            'body' => $this->replace($body, $variables),
        ];
    }

    public function find(string $templateKey, NotificationChannel $channel, ?int $institutionId): ?NotificationTemplate
    {
        $candidates = NotificationTemplate::query()
            ->where('template_key', $templateKey)
            ->where('channel', $channel)
            ->where('is_active', true)
            ->where(function ($query) use ($institutionId): void {
                $query->whereNull('institution_id');

                if ($institutionId !== null) {
                    $query->orWhere('institution_id', $institutionId);
                }
            })
            ->get();

        return $candidates->firstWhere('institution_id', $institutionId) ?? $candidates->firstWhere('institution_id', null);
    }

    /**
     * Placeholders found in a template body, e.g. ["name", "code"].
     *
     * @return array<int, string>
     */
    public function placeholders(string $text): array
    {
        preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @param  array<string, string|int|float|null>  $variables
     */
    private function replace(string $text, array $variables): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            fn (array $matches): string => array_key_exists($matches[1], $variables) ? (string) $variables[$matches[1]] : '',
            $text,
        );
    }
}
