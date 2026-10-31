<?php

namespace App\Modules\Academics\Models;

use App\Modules\Academics\Enums\TeachingComponent;
use App\Modules\HR\Models\Employee;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who teaches a class, for which component and how many hours a week.
 *
 * @property int $offering_section_id
 * @property int $employee_id
 * @property TeachingComponent $component
 * @property int $weekly_hours
 * @property-read OfferingSection $offeringSection
 * @property-read Employee $employee
 */
#[Fillable(['offering_section_id', 'employee_id', 'component', 'weekly_hours'])]
class FacultyAllocation extends TenantModel
{
    protected string $auditLog = 'academics';

    protected function casts(): array
    {
        return ['component' => TeachingComponent::class, 'weekly_hours' => 'integer'];
    }

    /**
     * @return BelongsTo<OfferingSection, $this>
     */
    public function offeringSection(): BelongsTo
    {
        return $this->belongsTo(OfferingSection::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
