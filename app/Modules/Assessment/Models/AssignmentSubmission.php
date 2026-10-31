<?php

namespace App\Modules\Assessment\Models;

use App\Modules\Students\Models\Student;
use App\Shared\Files\StoredFile;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $assignment_id
 * @property int $student_id
 * @property int|null $file_id
 * @property string|null $text
 * @property Carbon $submitted_at
 * @property bool $is_late
 * @property string|null $marks
 * @property string|null $feedback
 * @property int|null $evaluated_by
 * @property Carbon|null $evaluated_at
 * @property-read Assignment $assignment
 * @property-read Student $student
 * @property-read StoredFile|null $file
 */
#[Fillable(['assignment_id', 'student_id', 'file_id', 'text', 'submitted_at', 'is_late'])]
class AssignmentSubmission extends TenantModel
{
    protected string $auditLog = 'assessment';

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'evaluated_at' => 'datetime', 'is_late' => 'boolean', 'marks' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<Assignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'file_id');
    }
}
