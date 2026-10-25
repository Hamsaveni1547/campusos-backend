<?php

namespace App\Modules\Institution\Models;

use App\Modules\Institution\Enums\ProgrammeLevel;
use App\Modules\Institution\Enums\RecordStatus;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A degree programme, e.g. B.E. Computer Science (8 semesters).
 *
 * @property int $department_id
 * @property string $code
 * @property string $name
 * @property ProgrammeLevel $level
 * @property int $duration_years
 * @property int $total_terms
 * @property RecordStatus $status
 * @property-read Department $department
 */
#[Fillable(['department_id', 'code', 'name', 'level', 'duration_years', 'total_terms', 'intake_capacity', 'regulation', 'status'])]
class Programme extends TenantModel
{
    protected string $auditLog = 'institution';

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return [
            'level' => ProgrammeLevel::class,
            'status' => RecordStatus::class,
            'duration_years' => 'integer',
            'total_terms' => 'integer',
            'intake_capacity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return HasMany<Batch, $this>
     */
    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }
}
