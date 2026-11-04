<?php

namespace App\Modules\Feedback\Models;

use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * @property string $name
 * @property int $sla_hours hours to resolve before the complaint is overdue
 * @property bool $is_restricted only staff with the restricted permission see these (e.g. harassment)
 * @property string $status active|inactive
 */
#[Fillable(['name', 'sla_hours', 'is_restricted', 'status'])]
class ComplaintCategory extends TenantModel
{
    protected string $auditLog = 'feedback';

    protected function casts(): array
    {
        return ['is_restricted' => 'boolean'];
    }
}
