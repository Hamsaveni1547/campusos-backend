<?php

namespace App\Modules\Mentoring\Models;

use App\Modules\HR\Models\Employee;
use App\Modules\Students\Models\Student;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A student's mentor for a period; the current one has no end date.
 *
 * @property int $student_id
 * @property int $employee_id
 * @property Carbon $from_date
 * @property Carbon|null $to_date
 * @property string|null $reason
 * @property-read Student $student
 * @property-read Employee $mentor
 */
#[Fillable(['student_id', 'employee_id', 'from_date', 'to_date', 'reason'])]
class MentorAssignment extends TenantModel
{
    protected string $auditLog = 'mentoring';

    protected function casts(): array
    {
        return ['from_date' => 'date', 'to_date' => 'date'];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('to_date');
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function mentor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
