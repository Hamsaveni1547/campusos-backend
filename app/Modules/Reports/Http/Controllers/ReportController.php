<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Models\User;
use App\Modules\Reports\Support\ReportCatalogue;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @tags Reports
 */
class ReportController extends Controller
{
    public function __construct(private ReportCatalogue $catalogue) {}

    /**
     * Reports I may run.
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::list(collect($this->catalogue->availableTo($this->user($request)))->map(fn (array $d, string $key) => [...$d, 'key' => $key])->values(), fn (array $d): array => $this->definition($d['key'], $d));
    }

    /**
     * Run a report: filters in `filter[...]` (programme, exam_cycle, from, to).
     */
    public function run(Request $request, string $key): JsonResponse
    {
        $definition = $this->catalogue->definitions()[$key] ?? abort(404);
        abort_unless($this->user($request)->can($definition['permission']), 403);

        $filters = $request->validate([
            'filter.programme' => ['nullable', 'string', 'size:26'],
            'filter.exam_cycle' => ['nullable', 'string', 'size:26'],
            'filter.from' => ['nullable', 'date'],
            'filter.to' => ['nullable', 'date', 'after_or_equal:filter.from'],
        ])['filter'] ?? [];
        $filters = array_intersect_key(array_filter($filters), array_flip($definition['filters']));

        return ApiResponse::data([
            ...$this->definition($key, $definition),
            'applied_filters' => $filters,
            'rows' => $this->catalogue->run($key, $filters),
            'generated_at' => now()->toIso8601String(),
            'generated_by' => $this->user($request)->name,
        ]);
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private function definition(string $key, array $d): array
    {
        return [
            'key' => $key,
            'label' => $d['label'],
            'module' => $d['module'],
            'description' => $d['description'],
            'filters' => $d['filters'],
            'columns' => collect($d['columns'])->map(fn (array $c, string $k): array => ['key' => $k, 'label' => $c[0], 'type' => $c[1]])->values(),
        ];
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
