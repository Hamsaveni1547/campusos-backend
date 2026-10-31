<?php

namespace App\Modules\Assessment\Models;

use App\Models\User;
use App\Modules\Academics\Models\OfferingSection;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Internal marks of one class: draft → submitted → verified → locked.
 *
 * @property int $offering_section_id
 * @property string $status
 * @property array<string, mixed>|null $rules scheme used for the last computation
 * @property Carbon|null $computed_at
 * @property int|null $submitted_by
 * @property Carbon|null $submitted_at
 * @property int|null $verified_by
 * @property Carbon|null $verified_at
 * @property int|null $locked_by
 * @property Carbon|null $locked_at
 * @property string|null $remarks
 * @property-read OfferingSection $class
 * @property-read User|null $submitter
 * @property-read User|null $verifier
 */
#[Fillable(['offering_section_id'])]
class InternalMarkSheet extends TenantModel
{
    protected string $auditLog = 'assessment';

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['rules' => 'array', 'computed_at' => 'datetime', 'submitted_at' => 'datetime', 'verified_at' => 'datetime', 'locked_at' => 'datetime'];
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * @return BelongsTo<OfferingSection, $this>
     */
    public function class(): BelongsTo
    {
        return $this->belongsTo(OfferingSection::class, 'offering_section_id');
    }

    /**
     * @return HasMany<InternalMark, $this>
     */
    public function marks(): HasMany
    {
        return $this->hasMany(InternalMark::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
