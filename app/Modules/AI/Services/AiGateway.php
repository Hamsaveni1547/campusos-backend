<?php

namespace App\Modules\AI\Services;

use App\Models\User;
use App\Modules\AI\Contracts\LanguageModel;
use App\Modules\AI\Models\AiRequest;
use App\Modules\AI\Support\AiUnavailable;
use App\Modules\AI\Support\ModelReply;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Settings\SettingsRepository;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Support\Facades\Cache;

/**
 * The one door to AI: checks that AI and the feature are on, applies the daily limit per person,
 * calls the model and logs the call. Failures become a friendly "try again" error.
 */
class AiGateway
{
    /** Features that can be switched off in settings (group "ai"). */
    public const FEATURES = ['writing', 'complaints', 'feedback', 'assistant', 'documents'];

    public function __construct(private LanguageModel $model, private SettingsRepository $settings, private CurrentInstitution $institution) {}

    public function configured(): bool
    {
        return config('ai.provider') !== 'none';
    }

    public function enabled(string $feature): bool
    {
        return $this->configured()
            && (bool) $this->settings->get('ai', 'enabled')
            && (bool) $this->settings->get('ai', $feature);
    }

    /**
     * @param  callable(LanguageModel): ModelReply  $call
     */
    public function run(string $feature, User $user, callable $call): ModelReply
    {
        if (! $this->enabled($feature)) {
            throw new BusinessRuleException('This AI feature is switched off.', 'AI_DISABLED', 404);
        }

        $key = 'ai-calls:'.$user->id.':'.today()->toDateString();
        $limit = (int) $this->settings->get('ai', 'daily_limit_per_user');

        if ((int) Cache::get($key, 0) >= $limit) {
            $this->log($feature, $user, null, 0, 'limited', 'Daily limit reached');

            throw new BusinessRuleException("You have used today's {$limit} AI requests. Try again tomorrow.", 'AI_LIMIT', 429);
        }

        Cache::put($key, (int) Cache::get($key, 0) + 1, now()->endOfDay());
        $started = hrtime(true);

        try {
            $reply = $call($this->model);
        } catch (AiUnavailable $e) {
            $this->log($feature, $user, null, $this->ms($started), 'failed', $e->getMessage());

            throw new BusinessRuleException('AI is busy right now. Please try again in a moment.', 'AI_UNAVAILABLE', 503);
        }

        $this->log($feature, $user, $reply, $this->ms($started), 'ok');

        return $reply;
    }

    private function log(string $feature, User $user, ?ModelReply $reply, int $ms, string $status, ?string $error = null): void
    {
        AiRequest::query()->create([
            'institution_id' => $this->institution->id(),
            'user_id' => $user->id,
            'feature' => $feature,
            'provider' => $reply === null ? (string) config('ai.provider') : $reply->provider,
            'model' => $reply?->model,
            'input_tokens' => $reply === null ? 0 : $reply->inputTokens,
            'output_tokens' => $reply === null ? 0 : $reply->outputTokens,
            'duration_ms' => $ms,
            'status' => $status,
            'error' => $error === null ? null : mb_substr($error, 0, 300),
        ]);
    }

    private function ms(int|float $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
