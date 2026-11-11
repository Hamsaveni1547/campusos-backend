<?php

namespace App\Modules\AI\Http\Controllers;

use App\Modules\AI\AIServiceProvider;
use App\Modules\AI\Authorization\AIPermissions;
use App\Modules\AI\Services\ModelCatalog;
use App\Modules\AI\Support\AiUnavailable;
use App\Shared\Http\ApiResponse;
use App\Shared\Settings\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Which models each provider offers and which ones this college uses; a quick connection test.
 * The chosen models are saved through the settings API (group "ai").
 *
 * @tags AI
 */
class AiModelsController extends Controller
{
    private const PROVIDERS = ['groq', 'gemini'];

    public function index(Request $request, ModelCatalog $catalog, SettingsRepository $settings): JsonResponse
    {
        Gate::authorize(AIPermissions::UsageView);

        if ($request->boolean('refresh')) {
            $catalog->forget();
        }

        return ApiResponse::data([
            'provider' => config('ai.provider'),
            'fallback' => config('ai.fallback'),
            'providers' => collect(self::PROVIDERS)->map(fn (string $p): array => [
                'provider' => $p,
                'configured' => filled(config("ai.providers.{$p}.key")),
                'defaults' => (array) config("ai.providers.{$p}.models"),
                'chosen' => ['fast' => (string) $settings->get('ai', "{$p}_fast_model"), 'smart' => (string) $settings->get('ai', "{$p}_smart_model")],
                ...$catalog->for($p),
            ])->values(),
        ]);
    }

    /**
     * Send one tiny request to a provider with the models in use: `provider`.
     */
    public function test(Request $request): JsonResponse
    {
        Gate::authorize(AIPermissions::UsageView);
        $provider = (string) $request->validate(['provider' => ['required', Rule::in(self::PROVIDERS)]])['provider'];
        $client = AIServiceProvider::client($provider);

        if ($client === null) {
            return ApiResponse::data(['ok' => false, 'error' => 'No API key for '.$provider.' in the backend .env.']);
        }

        $started = hrtime(true);

        try {
            $reply = $client->chat([['role' => 'system', 'content' => 'Reply with one short word.'], ['role' => 'user', 'content' => 'Say OK.']], 'fast');

            return ApiResponse::data(['ok' => true, 'model' => $reply->model, 'reply' => mb_substr($reply->text, 0, 40), 'ms' => (int) ((hrtime(true) - $started) / 1_000_000)]);
        } catch (AiUnavailable $e) {
            return ApiResponse::data(['ok' => false, 'error' => $e->getMessage().($e->getPrevious() ? ' ('.$e->getPrevious()->getMessage().')' : '')]);
        }
    }
}
