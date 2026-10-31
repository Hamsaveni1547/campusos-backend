<?php

namespace App\Modules\Attendance\Models;

use App\Models\User;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shortage condoned for a student in a class (within the condonation band).
 *
 * @property int $student_id
 * @property int $offering_section_id
 * @property string $percentage_at_grant
 * @property string $reason
 * @property string|null $fee_reference
 * @property int|null $granted_by
 * @property-read User|null $granter
 */
#[Fillable(['student_id', 'offering_section_id', 'percentage_at_grant', 'reason', 'fee_reference', 'granted_by'])]
class AttendanceCondonation extends TenantModel
{
    protected string $auditLog = 'attendance';

    protected function casts(): array
    {
        return ['percentage_at_grant' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function granter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
