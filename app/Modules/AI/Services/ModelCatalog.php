<?php

namespace App\Modules\AI\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The chat models each provider currently offers for our key (live, cached for 6 hours).
 * Providers retire models often, so admins pick from this list instead of typing names.
 */
class ModelCatalog
{
    /** Not chat models: speech, image, safety classifiers, robotics and so on. */
    private const NOT_CHAT = '/whisper|orpheus|tts|guard|safeguard|image|robotics|computer-use|transcribe|lyria|deep-research|embedding|aqa|banana|omni|antigravity/i';

    /**
     * @return array{ok: bool, models: list<string>, error: string|null}
     */
    public function for(string $provider): array
    {
        return Cache::remember('ai-models:'.$provider, now()->addHours(6), fn (): array => $this->fetch($provider));
    }

    public function forget(): void
    {
        Cache::forget('ai-models:groq');
        Cache::forget('ai-models:gemini');
    }

    /**
     * @return array{ok: bool, models: list<string>, error: string|null}
     */
    private function fetch(string $provider): array
    {
        $key = config('ai.providers.'.$provider.'.key');

        if (blank($key)) {
            return ['ok' => false, 'models' => [], 'error' => 'No API key in the backend .env'];
        }

        try {
            $ids = match ($provider) {
                'groq' => collect(Http::withToken((string) $key)->timeout(20)->get((string) config('ai.providers.groq.models_url'))->throw()->json('data'))->pluck('id'),
                'gemini' => collect(Http::withHeaders(['x-goog-api-key' => (string) $key])->timeout(20)->get((string) config('ai.providers.gemini.url'), ['pageSize' => 200])->throw()->json('models'))
                    ->filter(fn (array $m): bool => in_array('generateContent', $m['supportedGenerationMethods'] ?? [], true))
                    ->map(fn (array $m): string => str_replace('models/', '', (string) $m['name'])),
                default => collect(),
            };

            return ['ok' => true, 'models' => $ids->reject(fn (string $id): bool => (bool) preg_match(self::NOT_CHAT, $id))->sort()->values()->all(), 'error' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'models' => [], 'error' => mb_substr($e->getMessage(), 0, 300)];
        }
    }
}
