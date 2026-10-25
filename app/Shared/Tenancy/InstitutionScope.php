<?php

namespace App\Shared\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limits queries to the current institution. When no institution is selected
 * the query matches nothing, so tenant data never leaks by accident.
 */
class InstitutionScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $institutionId = app(CurrentInstitution::class)->id();
        $column = $model->qualifyColumn('institution_id');

        if ($institutionId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($column, $institutionId);
    }
}
