<?php

namespace App\Shared\Http;

use App\Shared\Exceptions\BusinessRuleException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Small helpers for the standard `{ data, meta }` envelope.
 */
final class ApiResponse
{
    public static function data(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status);
    }

    /**
     * @template TItem
     *
     * @param  Collection<int, TItem>  $items
     * @param  callable(TItem): array<string, mixed>  $present
     */
    public static function list(Collection $items, callable $present): JsonResponse
    {
        return response()->json(['data' => $items->map($present)->values()]);
    }

    /**
     * @template TItem
     *
     * @param  LengthAwarePaginator<int, TItem>  $paginator
     * @param  callable(TItem): array<string, mixed>  $present
     */
    public static function paginated(LengthAwarePaginator $paginator, callable $present): JsonResponse
    {
        return response()->json([
            'data' => collect($paginator->items())->map($present)->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public static function message(string $message, int $status = 200): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }

    /**
     * Delete a record only when none of the given relations has rows.
     *
     * @param  array<string, string>  $relations  relation name => human label
     */
    public static function deleteUnlessUsed(Model $model, array $relations, string $what): JsonResponse
    {
        foreach ($relations as $relation => $label) {
            if ($model->{$relation}()->exists()) {
                throw new BusinessRuleException("This {$what} still has {$label}. Remove them first or mark it inactive.", 'IN_USE', 409);
            }
        }

        $model->delete();

        return self::message(ucfirst($what).' deleted.');
    }
}
