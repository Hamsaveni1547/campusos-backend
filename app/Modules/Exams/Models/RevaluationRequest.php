<?php

namespace App\Modules\Exams\Models;

use App\Models\User;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $exam_registration_id
 * @property string $reason
 * @property string $status pending|completed|rejected
 * @property string|null $old_marks
 * @property string|null $new_marks
 * @property string|null $remarks
 * @property int|null $requested_by
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property-read ExamRegistration $registration
 * @property-read User|null $requester
 */
#[Fillable(['exam_registration_id', 'reason', 'requested_by'])]
class RevaluationRequest extends TenantModel
{
    protected string $auditLog = 'exams';

    protected function casts(): array
    {
        return ['old_marks' => 'decimal:2', 'new_marks' => 'decimal:2', 'decided_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<ExamRegistration, $this>
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(ExamRegistration::class, 'exam_registration_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
