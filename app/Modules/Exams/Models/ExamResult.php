<?php

namespace App\Modules\Exams\Models;

use App\Modules\Students\Models\Student;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $exam_cycle_id
 * @property int $student_id
 * @property string $credits_registered
 * @property string $credits_earned
 * @property string|null $sgpa
 * @property string|null $cgpa
 * @property int $backlogs
 * @property-read ExamCycle $cycle
 * @property-read Student $student
 */
#[Fillable(['exam_cycle_id', 'student_id', 'credits_registered', 'credits_earned', 'sgpa', 'cgpa', 'backlogs'])]
class ExamResult extends TenantModel
{
    protected string $auditLog = 'exams';

    protected function casts(): array
    {
        return ['credits_registered' => 'decimal:1', 'credits_earned' => 'decimal:1', 'sgpa' => 'decimal:2', 'cgpa' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<ExamCycle, $this>
     */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ExamCycle::class, 'exam_cycle_id');
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
