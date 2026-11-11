<?php

namespace App\Modules\AI;

use App\Modules\AI\Clients\FallbackModel;
use App\Modules\AI\Clients\GeminiClient;
use App\Modules\AI\Clients\GroqClient;
use App\Modules\AI\Contracts\LanguageModel;
use App\Shared\Settings\SettingsRegistry;
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
                $config = (array) config('ai.providers.'.$provider);
                if (filled($config['key'] ?? null)) {
                    $models[] = new (self::CLIENTS[$provider])($config, (int) config('ai.timeout'));
                }
            }

            return new FallbackModel($models);
        });
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
        ]);
    }
}
