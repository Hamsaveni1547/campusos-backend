<?php

namespace App\Modules\AI;

use App\Modules\AI\Clients\FallbackModel;
use App\Modules\AI\Clients\GeminiClient;
use App\Modules\AI\Clients\GroqClient;
use App\Modules\AI\Contracts\LanguageModel;
use App\Shared\Settings\SettingsRegistry;
use App\Shared\Settings\SettingsRepository;
use Illuminate\Support\ServiceProvider;

class AIServiceProvider extends ServiceProvider
{
    /** @var array<string, class-string<LanguageModel>> */
    private const CLIENTS = ['groq' => GroqClient::class, 'gemini' => GeminiClient::class];

    public function register(): void
    {
        // Main provider first, then the fallback; providers without a key are skipped.
        $this->app->bind(LanguageModel::class, function (): LanguageModel {
            $order = array_unique(array_filter([(string) config('ai.provider'), (string) config('ai.fallback')], fn (string $p): bool => isset(self::CLIENTS[$p])));
            $models = [];

            foreach ($order as $provider) {
                $client = self::client($provider);
                if ($client !== null) {
                    $models[] = $client;
                }
            }

            return new FallbackModel($models);
        });
    }

    /**
     * One provider's client with the models chosen in settings (AI → Models), else the server defaults.
     */
    public static function client(string $provider): ?LanguageModel
    {
        $config = (array) config('ai.providers.'.$provider);

        if (! isset(self::CLIENTS[$provider]) || blank($config['key'] ?? null)) {
            return null;
        }

        foreach (['fast', 'smart'] as $tier) {
            $chosen = rescue(fn () => app(SettingsRepository::class)->get('ai', "{$provider}_{$tier}_model"), null, false);
            if (filled($chosen)) {
                $config['models'][$tier] = (string) $chosen;
            }
        }

        return new (self::CLIENTS[$provider])($config, (int) config('ai.timeout'));
    }

    public function boot(SettingsRegistry $settings): void
    {
        $settings->register('ai', 'AI assistant', [
            'enabled' => ['type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'label' => 'Use AI features (needs an API key on the server)'],
            'writing' => ['type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'label' => 'Announcement writer, summaries and Kannada translation'],
            'complaints' => ['type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'label' => 'Complaint category suggestion and triage'],
            'feedback' => ['type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'label' => 'Insights from feedback comments'],
            'assistant' => ['type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'label' => 'Ask CampusOS chat assistant'],
            'documents' => ['type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'label' => 'Reading admission documents (Gemini)'],
            'daily_limit_per_user' => ['type' => 'int', 'default' => 50, 'rules' => ['integer', 'min:1', 'max:1000'], 'label' => 'AI requests per person per day'],
            // Models (empty = server default). Pick them from the live list on AI usage → Models.
            'groq_fast_model' => ['type' => 'string', 'default' => '', 'rules' => ['nullable', 'string', 'max:120'], 'label' => 'Groq model for quick tasks (empty = default)'],
            'groq_smart_model' => ['type' => 'string', 'default' => '', 'rules' => ['nullable', 'string', 'max:120'], 'label' => 'Groq model for writing and the assistant (empty = default)'],
            'gemini_fast_model' => ['type' => 'string', 'default' => '', 'rules' => ['nullable', 'string', 'max:120'], 'label' => 'Gemini model for quick tasks (empty = default)'],
            'gemini_smart_model' => ['type' => 'string', 'default' => '', 'rules' => ['nullable', 'string', 'max:120'], 'label' => 'Gemini model for writing, assistant and documents (empty = default)'],
        ]);
    }
}
