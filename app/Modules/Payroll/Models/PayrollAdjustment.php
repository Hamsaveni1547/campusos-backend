<?php

namespace App\Modules\Payroll\Models;

use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * A one-off earning (e.g. honorarium, arrears) or deduction (e.g. advance recovery) for one month.
 *
 * @property int $payslip_id
 * @property string $kind earning|deduction
 * @property string $label
 * @property string $amount
 */
#[Fillable(['payslip_id', 'kind', 'label', 'amount'])]
class PayrollAdjustment extends TenantModel
{
    protected string $auditLog = 'payroll';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
