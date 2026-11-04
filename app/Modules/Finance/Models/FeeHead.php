<?php

namespace App\Modules\Finance\Models;

use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $status active|inactive
 */
#[Fillable(['code', 'name', 'description', 'status'])]
class FeeHead extends TenantModel
{
    protected string $auditLog = 'finance';
}
