<?php

namespace App\Modules\Payroll\Models;

use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * An earning or deduction: a fixed amount per employee, or a percentage of BASIC (optionally capped).
 *
 * @property string $code
 * @property string $name
 * @property string $kind earning|deduction
 * @property string $calculation fixed|percent
 * @property string|null $percent
 * @property string|null $max_amount
 * @property bool $affected_by_lop
 * @property int $position
 * @property string $status active|inactive
 */
#[Fillable(['code', 'name', 'kind', 'calculation', 'percent', 'max_amount', 'affected_by_lop', 'position', 'status'])]
class PayComponent extends TenantModel
{
    protected string $auditLog = 'payroll';

    protected function casts(): array
    {
        return ['percent' => 'decimal:3', 'max_amount' => 'decimal:2', 'affected_by_lop' => 'boolean'];
    }
}
