<?php

namespace App\Modules\Payroll\Models;

use App\Modules\HR\Models\Employee;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One component of an employee's salary (amount for fixed components; empty for percentage ones).
 *
 * @property int $employee_id
 * @property int $pay_component_id
 * @property string|null $amount
 * @property-read Employee $employee
 * @property-read PayComponent $component
 */
#[Fillable(['employee_id', 'pay_component_id', 'amount'])]
class EmployeeSalary extends TenantModel
{
    protected string $auditLog = 'payroll';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<PayComponent, $this>
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class, 'pay_component_id');
    }
}
