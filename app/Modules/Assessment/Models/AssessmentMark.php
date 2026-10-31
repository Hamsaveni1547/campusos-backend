<?php

namespace App\Modules\Assessment\Models;

use App\Modules\Students\Models\Student;
use App\Shared\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $institution_id
 * @property int $id
 * @property int $assessment_id
 * @property int $student_id
 * @property string|null $marks
 * @property string $status present|absent|exempt
 * @property int|null $entered_by
 * @property-read Assessment $assessment
 * @property-read Student $student
 */
#[Fillable(['assessment_id', 'student_id', 'marks', 'status', 'entered_by'])]
class AssessmentMark extends Model
{
    use BelongsToInstitution;

    protected function casts(): array
    {
        return ['marks' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<Assessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
