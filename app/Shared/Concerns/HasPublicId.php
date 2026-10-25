<?php

namespace App\Shared\Concerns;

use Illuminate\Support\Str;

/**
 * Gives a model a public ULID identifier used in URLs and API payloads,
 * keeping the numeric primary key internal.
 */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->ulid)) {
                $model->ulid = (string) Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    public static function findByPublicId(string $ulid): ?static
    {
        return static::query()->where('ulid', $ulid)->first();
    }
}
