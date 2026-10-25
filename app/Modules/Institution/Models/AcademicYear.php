<?php

namespace App\Modules\Institution\Models;

use App\Modules\Institution\Enums\PeriodStatus;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $name
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property bool $is_current
 * @property PeriodStatus $status
 */
#[Fillable(['name', 'starts_on', 'ends_on', 'is_current', 'status'])]
class AcademicYear extends TenantModel
{
    protected string $auditLog = 'institution';

    protected $attributes = ['status' => 'planned', 'is_current' => false];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_current' => 'boolean',
            'status' => PeriodStatus::class,
        ];
    }

    /**
     * @return HasMany<Term, $this>
     */
    public function terms(): HasMany
    {
        return $this->hasMany(Term::class)->orderBy('starts_on');
    }
}
