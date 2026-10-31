<?php

namespace App\Modules\Exams\Models;

use App\Models\User;
use App\Modules\Institution\Models\Term;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $term_id
 * @property string $name
 * @property string $type regular|supplementary
 * @property string $status draft|registration_open|registration_closed|results_processed|results_verified|published
 * @property Carbon|null $registration_ends_on
 * @property int|null $processed_by
 * @property Carbon|null $processed_at
 * @property int|null $verified_by
 * @property Carbon|null $verified_at
 * @property int|null $published_by
 * @property Carbon|null $published_at
 * @property-read Term $term
 * @property-read User|null $processor
 * @property-read User|null $verifier
 */
#[Fillable(['term_id', 'name', 'type', 'registration_ends_on'])]
class ExamCycle extends TenantModel
{
    public const TYPES = ['regular', 'supplementary'];

    public const STATUSES = ['draft', 'registration_open', 'registration_closed', 'results_processed', 'results_verified', 'published'];

    protected string $auditLog = 'exams';

    protected function casts(): array
    {
        return ['registration_ends_on' => 'date', 'processed_at' => 'datetime', 'verified_at' => 'datetime', 'published_at' => 'datetime'];
    }

    /**
     * Marks and eligibility can change only between closing registration and verifying results.
     */
    public function isEvaluating(): bool
    {
        return in_array($this->status, ['registration_closed', 'results_processed'], true);
    }

    /**
     * @return BelongsTo<Term, $this>
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * @return HasMany<ExamSchedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(ExamSchedule::class);
    }

    /**
     * @return HasMany<ExamRegistration, $this>
     */
    public function registrations(): HasMany
    {
        return $this->hasMany(ExamRegistration::class);
    }

    /**
     * @return HasMany<ExamResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(ExamResult::class);
    }
}
