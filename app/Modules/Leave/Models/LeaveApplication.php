<?php

namespace App\Modules\Leave\Models;

use App\Models\User;
use App\Modules\HR\Models\Employee;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $employee_id
 * @property int $leave_type_id
 * @property Carbon $from_date
 * @property Carbon $to_date
 * @property bool $half_day
 * @property string $days working days (Sundays and holidays excluded)
 * @property string $reason
 * @property string $status pending|approved|rejected|cancelled
 * @property string|null $remarks
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property-read Employee $employee
 * @property-read LeaveType $type
 * @property-read User|null $decider
 */
#[Fillable(['employee_id', 'leave_type_id', 'from_date', 'to_date', 'half_day', 'days', 'reason'])]
class LeaveApplication extends TenantModel
{
    protected string $auditLog = 'leave';

    protected function casts(): array
    {
        return ['from_date' => 'date', 'to_date' => 'date', 'half_day' => 'boolean', 'days' => 'decimal:1', 'decided_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<LeaveType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
