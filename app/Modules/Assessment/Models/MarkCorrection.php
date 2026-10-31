<?php

namespace App\Modules\Assessment\Models;

use App\Models\User;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A change to a mark after the class's internal marks were submitted.
 *
 * @property int $assessment_mark_id
 * @property string|null $requested_marks
 * @property string $requested_status
 * @property string $reason
 * @property string $status
 * @property string|null $remarks
 * @property int|null $requested_by
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $created_at
 * @property-read AssessmentMark $mark
 * @property-read User|null $requester
 */
#[Fillable(['assessment_mark_id', 'requested_marks', 'requested_status', 'reason', 'requested_by'])]
class MarkCorrection extends TenantModel
{
    protected string $auditLog = 'assessment';

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['requested_marks' => 'decimal:2', 'reviewed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<AssessmentMark, $this>
     */
    public function mark(): BelongsTo
    {
        return $this->belongsTo(AssessmentMark::class, 'assessment_mark_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
