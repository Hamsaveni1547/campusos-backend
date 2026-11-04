<?php

namespace App\Modules\Finance\Models;

use App\Modules\Institution\Models\Programme;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Fees for students of a programme, optionally narrowed to an admission year, quota and semester.
 *
 * @property string $name
 * @property int $programme_id
 * @property int|null $admission_year
 * @property string|null $quota
 * @property int|null $term_number
 * @property Carbon $due_on
 * @property string $status active|inactive
 * @property-read Programme $programme
 */
#[Fillable(['name', 'programme_id', 'admission_year', 'quota', 'term_number', 'due_on', 'status'])]
class FeeStructure extends TenantModel
{
    protected string $auditLog = 'finance';

    protected function casts(): array
    {
        return ['due_on' => 'date'];
    }

    public function total(): float
    {
        return (float) $this->items->sum('amount');
    }

    /**
     * @return BelongsTo<Programme, $this>
     */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    /**
     * @return HasMany<FeeStructureItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(FeeStructureItem::class);
    }

    /**
     * @return HasMany<FeeDemand, $this>
     */
    public function demands(): HasMany
    {
        return $this->hasMany(FeeDemand::class);
    }
}
