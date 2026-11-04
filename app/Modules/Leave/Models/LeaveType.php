<?php

namespace App\Modules\Leave\Models;

use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * @property string $code
 * @property string $name
 * @property string $days_per_year
 * @property bool $is_paid unpaid leave becomes loss of pay in payroll
 * @property bool $allow_half_day
 * @property string $status active|inactive
 */
#[Fillable(['code', 'name', 'days_per_year', 'is_paid', 'allow_half_day', 'status'])]
class LeaveType extends TenantModel
{
    protected string $auditLog = 'leave';

    protected function casts(): array
    {
        return ['days_per_year' => 'decimal:1', 'is_paid' => 'boolean', 'allow_half_day' => 'boolean'];
    }
}
