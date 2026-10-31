<?php

namespace App\Modules\Students\Models;

use App\Modules\Students\Enums\GuardianRelation;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * @property int $student_id
 * @property GuardianRelation $relation
 * @property string $name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $occupation
 * @property string|null $annual_income
 * @property bool $is_primary
 */
#[Fillable(['student_id', 'relation', 'name', 'phone', 'email', 'occupation', 'annual_income', 'is_primary'])]
class StudentGuardian extends TenantModel
{
    protected string $auditLog = 'students';

    protected function casts(): array
    {
        return ['relation' => GuardianRelation::class, 'is_primary' => 'boolean', 'annual_income' => 'decimal:2'];
    }
}
