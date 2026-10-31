<?php

namespace App\Modules\Admissions\Models;

use App\Modules\Admissions\Enums\Quota;
use App\Modules\Institution\Models\Programme;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Seat matrix row: seats of one programme under one quota in a cycle.
 *
 * @property int $admission_cycle_id
 * @property int $programme_id
 * @property Quota $quota
 * @property int $total
 * @property int $filled
 * @property string|null $min_percentage
 * @property string $admission_fee
 * @property-read Programme $programme
 * @property-read AdmissionCycle $cycle
 */
#[Fillable(['admission_cycle_id', 'programme_id', 'quota', 'total', 'min_percentage', 'admission_fee'])]
class AdmissionSeat extends TenantModel
{
    protected string $auditLog = 'admissions';

    protected $attributes = ['filled' => 0, 'admission_fee' => 0];

    protected function casts(): array
    {
        return [
            'quota' => Quota::class,
            'total' => 'integer',
            'filled' => 'integer',
            'min_percentage' => 'decimal:2',
            'admission_fee' => 'decimal:2',
        ];
    }

    public function available(): int
    {
        return max(0, $this->total - $this->filled);
    }

    /**
     * @return BelongsTo<Programme, $this>
     */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    /**
     * @return BelongsTo<AdmissionCycle, $this>
     */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(AdmissionCycle::class, 'admission_cycle_id');
    }

    /**
     * @return HasMany<Application, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }
}
