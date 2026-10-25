<?php

namespace App\Modules\HR\Models;

use App\Models\User;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Enums\EmploymentType;
use App\Modules\HR\Enums\StaffType;
use App\Modules\Institution\Models\Department;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Staff record (teaching or non-teaching) linked to a user account.
 *
 * @property int $user_id
 * @property string $employee_code
 * @property StaffType $staff_type
 * @property EmploymentType $employment_type
 * @property int|null $department_id
 * @property int|null $designation_id
 * @property int|null $reports_to_id
 * @property Carbon $joined_on
 * @property Carbon|null $contract_ends_on
 * @property Carbon|null $left_on
 * @property Carbon|null $date_of_birth
 * @property EmployeeStatus $status
 * @property-read User $user
 * @property-read Department|null $department
 * @property-read Designation|null $designation
 * @property-read Employee|null $reportsTo
 */
#[Fillable([
    'user_id', 'employee_code', 'staff_type', 'employment_type', 'department_id', 'designation_id', 'reports_to_id',
    'joined_on', 'contract_ends_on', 'left_on', 'status', 'exit_reason', 'gender', 'date_of_birth',
    'highest_qualification', 'specialization', 'prior_experience_years', 'address',
    'emergency_contact_name', 'emergency_contact_phone',
])]
class Employee extends TenantModel
{
    protected string $auditLog = 'hr';

    protected $attributes = ['status' => 'active', 'prior_experience_years' => 0];

    protected function casts(): array
    {
        return [
            'staff_type' => StaffType::class,
            'employment_type' => EmploymentType::class,
            'status' => EmployeeStatus::class,
            'joined_on' => 'date',
            'contract_ends_on' => 'date',
            'left_on' => 'date',
            'date_of_birth' => 'date',
            'prior_experience_years' => 'decimal:1',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<Designation, $this>
     */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function reportsTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reports_to_id');
    }

    /**
     * @return HasMany<EmployeeQualification, $this>
     */
    public function qualifications(): HasMany
    {
        return $this->hasMany(EmployeeQualification::class)->orderByDesc('year_of_passing');
    }

    /**
     * Years at this institution plus prior experience.
     */
    public function totalExperienceYears(): float
    {
        $end = $this->left_on ?? now();

        return round((float) $this->prior_experience_years + $this->joined_on->diffInDays($end, true) / 365.25, 1);
    }
}
