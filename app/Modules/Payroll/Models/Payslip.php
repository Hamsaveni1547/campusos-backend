<?php

namespace App\Modules\Payroll\Models;

use App\Modules\HR\Models\Employee;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $payroll_run_id
 * @property int $employee_id
 * @property int $days_in_month
 * @property string $lop_days
 * @property string $gross
 * @property string $deductions
 * @property string $net
 * @property list<array{code: string|null, name: string, kind: string, amount: float}>|null $lines
 * @property-read PayrollRun $run
 * @property-read Employee $employee
 */
#[Fillable(['payroll_run_id', 'employee_id', 'days_in_month', 'lop_days'])]
class Payslip extends TenantModel
{
    protected string $auditLog = 'payroll';

    protected function casts(): array
    {
        return ['lop_days' => 'decimal:1', 'gross' => 'decimal:2', 'deductions' => 'decimal:2', 'net' => 'decimal:2', 'lines' => 'array'];
    }

    /**
     * @return BelongsTo<PayrollRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return HasMany<PayrollAdjustment, $this>
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(PayrollAdjustment::class);
    }
}
