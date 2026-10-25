<?php

namespace App\Modules\Institution\Models;

use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Institution\Enums\DepartmentType;
use App\Modules\Institution\Enums\RecordStatus;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $code
 * @property string $name
 * @property DepartmentType $type
 * @property RecordStatus $status
 */
#[Fillable(['code', 'name', 'type', 'status', 'description', 'email', 'phone', 'established_year'])]
class Department extends TenantModel
{
    protected string $auditLog = 'institution';

    protected $attributes = ['type' => 'academic', 'status' => 'active'];

    protected function casts(): array
    {
        return [
            'type' => DepartmentType::class,
            'status' => RecordStatus::class,
            'established_year' => 'integer',
        ];
    }

    /**
     * @return HasMany<Programme, $this>
     */
    public function programmes(): HasMany
    {
        return $this->hasMany(Programme::class);
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * @return HasMany<Room, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    /**
     * Current head of department (active HOD role assignment), if any.
     */
    public function currentHodAssignment(): ?RoleAssignment
    {
        return RoleAssignment::query()
            ->effective()
            ->where('department_id', $this->id)
            ->whereHas('role', fn ($query) => $query->where('name', 'hod'))
            ->with('user')
            ->latest('id')
            ->first();
    }
}
