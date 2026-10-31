<?php

namespace App\Modules\Attendance\Models;

use App\Models\User;
use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A request to change one attendance mark after the edit window.
 *
 * @property int $attendance_record_id
 * @property AttendanceStatus $requested_status
 * @property string $reason
 * @property string $status pending|approved|rejected
 * @property string|null $remarks
 * @property int|null $requested_by
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $created_at
 * @property-read AttendanceRecord $record
 * @property-read User|null $requester
 * @property-read User|null $reviewer
 */
#[Fillable(['attendance_record_id', 'requested_status', 'reason', 'requested_by'])]
class AttendanceCorrection extends TenantModel
{
    protected string $auditLog = 'attendance';

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['requested_status' => AttendanceStatus::class, 'reviewed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<AttendanceRecord, $this>
     */
    public function record(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class, 'attendance_record_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
