<?php

namespace App\Modules\Leave\Models;

use App\Modules\HR\Models\Employee;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Days allotted to an employee for a leave type in a calendar year (used days come from applications).
 *
 * @property int $employee_id
 * @property int $leave_type_id
 * @property int $year
 * @property string $allotted
 * @property-read Employee $employee
 * @property-read LeaveType $type
 */
#[Fillable(['employee_id', 'leave_type_id', 'year', 'allotted'])]
class LeaveBalance extends TenantModel
{
    protected string $auditLog = 'leave';

    protected function casts(): array
    {
        return ['allotted' => 'decimal:1'];
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
}
