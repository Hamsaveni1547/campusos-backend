<?php

namespace App\Modules\AI\Clients;

use App\Modules\AI\Support\AiUnavailable;

/**
 * Parses a model's JSON answer, tolerating code fences around it.
 */
final class Json
{
    /**
     * @return array<string, mixed>
     */
    public static function decode(string $text): array
    {
        $clean = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text)));
        $data = json_decode($clean, true);

        if (! is_array($data)) {
            throw new AiUnavailable('The AI answer was not valid JSON.');
        }

        return $data;
    }
}
