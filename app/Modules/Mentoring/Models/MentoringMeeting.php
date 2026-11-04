<?php

namespace App\Modules\Mentoring\Models;

use App\Modules\HR\Models\Employee;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A mentoring meeting with one or more mentees. Students can request one; the mentor schedules it
 * and records minutes and who attended.
 *
 * @property int $employee_id
 * @property list<int> $student_ids
 * @property string $status requested|scheduled|held|cancelled
 * @property Carbon|null $scheduled_at
 * @property string $agenda
 * @property string|null $minutes
 * @property list<int>|null $attended_ids
 * @property int|null $created_by
 * @property-read Employee $mentor
 */
#[Fillable(['employee_id', 'student_ids', 'scheduled_at', 'agenda'])]
class MentoringMeeting extends TenantModel
{
    protected string $auditLog = 'mentoring';

    protected function casts(): array
    {
        return ['student_ids' => 'array', 'attended_ids' => 'array', 'scheduled_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function mentor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
