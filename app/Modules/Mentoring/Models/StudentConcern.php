<?php

namespace App\Modules\Mentoring\Models;

use App\Models\User;
use App\Modules\Students\Models\Student;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A concern about a mentee with the plan to address it.
 *
 * @property int $student_id
 * @property string $category academic|attendance|personal|financial|health|other
 * @property string $description
 * @property string|null $action_plan
 * @property Carbon|null $review_on
 * @property string $status open|resolved
 * @property string|null $outcome
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property-read Student $student
 * @property-read User|null $raiser
 */
#[Fillable(['student_id', 'category', 'description', 'action_plan', 'review_on'])]
class StudentConcern extends TenantModel
{
    public const CATEGORIES = ['academic', 'attendance', 'personal', 'financial', 'health', 'other'];

    protected string $auditLog = 'mentoring';

    protected function casts(): array
    {
        return ['review_on' => 'date', 'resolved_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function raiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
