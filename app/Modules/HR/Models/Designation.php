<?php

namespace App\Modules\HR\Models;

use App\Modules\HR\Enums\StaffType;
use App\Modules\Institution\Enums\RecordStatus;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Job title such as Professor, Assistant Professor, Lab Assistant, Office Clerk.
 *
 * @property string $name
 * @property StaffType $category
 * @property int $rank
 * @property RecordStatus $status
 */
#[Fillable(['name', 'category', 'rank', 'status'])]
class Designation extends TenantModel
{
    protected string $auditLog = 'hr';

    protected $attributes = ['rank' => 100, 'status' => 'active'];

    protected function casts(): array
    {
        return [
            'category' => StaffType::class,
            'status' => RecordStatus::class,
            'rank' => 'integer',
        ];
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
