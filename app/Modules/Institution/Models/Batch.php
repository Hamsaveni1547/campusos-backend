<?php

namespace App\Modules\Institution\Models;

use App\Modules\Institution\Enums\BatchStatus;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Students of one programme admitted in the same year, e.g. "B.E. CSE 2026".
 *
 * @property int $programme_id
 * @property int $admission_year
 * @property string $name
 * @property BatchStatus $status
 * @property-read Programme $programme
 */
#[Fillable(['programme_id', 'admission_year', 'name', 'status'])]
class Batch extends TenantModel
{
    protected string $auditLog = 'institution';

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return [
            'admission_year' => 'integer',
            'status' => BatchStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Programme, $this>
     */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    /**
     * @return HasMany<Section, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(Section::class)->orderBy('name');
    }

    /**
     * Expected year of completion.
     */
    public function graduationYear(): int
    {
        return $this->admission_year + $this->programme->duration_years;
    }
}
