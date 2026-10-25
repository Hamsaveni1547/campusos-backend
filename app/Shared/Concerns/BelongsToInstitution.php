<?php

namespace App\Shared\Concerns;

use App\Modules\Institution\Models\Institution;
use App\Shared\Tenancy\CurrentInstitution;
use App\Shared\Tenancy\InstitutionScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scopes a model to the current institution and fills institution_id on create.
 */
trait BelongsToInstitution
{
    public static function bootBelongsToInstitution(): void
    {
        static::addGlobalScope(new InstitutionScope);

        static::creating(function (self $model): void {
            if (empty($model->institution_id)) {
                $model->institution_id = app(CurrentInstitution::class)->id();
            }
        });
    }

    /**
     * @return BelongsTo<Institution, $this>
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * Query across every institution (platform-level use only).
     *
     * @return Builder<static>
     */
    public static function acrossInstitutions(): Builder
    {
        return static::query()->withoutGlobalScope(InstitutionScope::class);
    }
}
