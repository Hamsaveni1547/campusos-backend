<?php

namespace App\Modules\Exams\Models;

use App\Modules\Academics\Models\Course;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $exam_cycle_id
 * @property int $course_id
 * @property Carbon $exam_date
 * @property string $starts_at
 * @property string $ends_at
 * @property string $max_marks
 * @property-read ExamCycle $cycle
 * @property-read Course $course
 */
#[Fillable(['exam_cycle_id', 'course_id', 'exam_date', 'starts_at', 'ends_at', 'max_marks'])]
class ExamSchedule extends TenantModel
{
    protected string $auditLog = 'exams';

    protected function casts(): array
    {
        return ['exam_date' => 'date', 'max_marks' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<ExamCycle, $this>
     */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ExamCycle::class, 'exam_cycle_id');
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
