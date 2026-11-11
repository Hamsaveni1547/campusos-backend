<?php

namespace App\Modules\AI\Http\Controllers;

use App\Modules\AI\Authorization\AIPermissions;
use App\Modules\AI\Models\AiRequest;
use App\Shared\Http\ApiResponse;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * How much AI the institution uses: calls by day and by feature, tokens, failures (last N days).
 *
 * @tags AI
 */
class AiUsageController extends Controller
{
    public function __invoke(Request $request, CurrentInstitution $institution): JsonResponse
    {
        Gate::authorize(AIPermissions::UsageView);
        $days = (int) ($request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:90']])['days'] ?? 14);
        $rows = AiRequest::query()->where('institution_id', $institution->require()->id)->where('created_at', '>=', today()->subDays($days - 1))->get();

        return ApiResponse::data([
            'provider' => config('ai.provider'),
            'fallback' => config('ai.fallback'),
            'days' => $days,
            'totals' => [
                'calls' => $rows->count(),
                'failed' => $rows->where('status', 'failed')->count(),
                'limited' => $rows->where('status', 'limited')->count(),
                'tokens' => (int) $rows->sum(fn (AiRequest $r): int => $r->input_tokens + $r->output_tokens),
                'average_ms' => (int) round((float) $rows->where('status', 'ok')->avg('duration_ms')),
            ],
            'by_day' => collect(range($days - 1, 0))->map(function (int $ago) use ($rows): array {
                $date = today()->subDays($ago)->toDateString();
                $day = $rows->filter(fn (AiRequest $r): bool => $r->created_at->toDateString() === $date);

                return ['date' => $date, 'calls' => $day->count(), 'failed' => $day->where('status', 'failed')->count()];
            })->values(),
            'by_feature' => $rows->groupBy('feature')->map(fn ($g, string $feature): array => ['feature' => $feature, 'calls' => $g->count(), 'failed' => $g->where('status', 'failed')->count()])->sortByDesc('calls')->values(),
            'by_provider' => $rows->where('status', 'ok')->groupBy('provider')->map(fn ($g, string $provider): array => ['provider' => $provider, 'calls' => $g->count()])->values(),
        ]);
    }
}
