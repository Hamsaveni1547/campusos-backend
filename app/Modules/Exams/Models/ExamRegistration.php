<?php

namespace App\Modules\Exams\Models;

use App\Modules\Academics\Models\Course;
use App\Modules\Students\Models\Student;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One student taking one course's exam in a cycle: eligibility, script code, marks and result.
 *
 * @property int $exam_cycle_id
 * @property int $student_id
 * @property int $course_id
 * @property int|null $course_offering_id
 * @property string $kind regular|backlog
 * @property string $eligibility pending|eligible|not_eligible
 * @property list<string>|null $eligibility_reasons
 * @property string|null $override_reason
 * @property int|null $overridden_by
 * @property string|null $script_code
 * @property string|null $attendance present|absent
 * @property string|null $external_marks
 * @property int|null $marks_entered_by
 * @property string|null $internal_marks
 * @property string|null $internal_out_of
 * @property string|null $total_percent
 * @property string|null $grade
 * @property string|null $grade_points
 * @property bool|null $passed
 * @property int $revision
 * @property-read ExamCycle $cycle
 * @property-read Student $student
 * @property-read Course $course
 */
#[Fillable(['exam_cycle_id', 'student_id', 'course_id', 'course_offering_id', 'kind'])]
class ExamRegistration extends TenantModel
{
    protected string $auditLog = 'exams';

    protected function casts(): array
    {
        return [
            'eligibility_reasons' => 'array', 'passed' => 'boolean', 'external_marks' => 'decimal:2', 'internal_marks' => 'decimal:2',
            'internal_out_of' => 'decimal:2', 'total_percent' => 'decimal:2', 'grade_points' => 'decimal:2',
        ];
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

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
