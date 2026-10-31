<?php

namespace App\Modules\Assessment\Models;

use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One test, quiz, assignment, lab record or viva of a course offering (all its sections).
 *
 * @property int $assessment_group_id
 * @property string $name
 * @property string $kind
 * @property Carbon|null $held_on
 * @property string $max_marks
 * @property list<string>|null $outcome_codes
 * @property-read AssessmentGroup $group
 */
#[Fillable(['assessment_group_id', 'name', 'kind', 'held_on', 'max_marks', 'outcome_codes'])]
class Assessment extends TenantModel
{
    protected string $auditLog = 'assessment';

    protected function casts(): array
    {
        return ['held_on' => 'date', 'max_marks' => 'decimal:2', 'outcome_codes' => 'array'];
    }

    /**
     * @return BelongsTo<AssessmentGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(AssessmentGroup::class, 'assessment_group_id');
    }

    /**
     * @return HasMany<AssessmentMark, $this>
     */
    public function marks(): HasMany
    {
        return $this->hasMany(AssessmentMark::class);
    }
}
