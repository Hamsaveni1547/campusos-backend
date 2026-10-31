<?php

namespace App\Modules\Academics\Models;

use App\Models\User;
use App\Modules\Academics\Enums\CurriculumStatus;
use App\Modules\Institution\Models\Programme;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Scheme of a programme under one regulation: which course in which term. Versioned.
 *
 * @property int $programme_id
 * @property string $regulation
 * @property int $version
 * @property CurriculumStatus $status
 * @property string|null $remarks
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property-read Programme $programme
 * @property-read User|null $approver
 */
#[Fillable(['programme_id', 'regulation', 'version', 'remarks'])]
class Curriculum extends TenantModel
{
    protected string $auditLog = 'academics';

    protected $table = 'curricula';

    protected $attributes = ['status' => 'draft', 'version' => 1];

    protected function casts(): array
    {
        return ['status' => CurriculumStatus::class, 'version' => 'integer', 'approved_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Programme, $this>
     */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    /**
     * @return HasMany<CurriculumCourse, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(CurriculumCourse::class)->orderBy('term_number')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
