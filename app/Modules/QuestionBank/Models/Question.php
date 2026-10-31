<?php

namespace App\Modules\QuestionBank\Models;

use App\Modules\Academics\Models\Course;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $course_id
 * @property int|null $unit
 * @property string $type mcq|short|long|numerical
 * @property string $marks
 * @property string|null $co_code
 * @property string|null $bloom_level
 * @property string $difficulty easy|medium|hard
 * @property string $encrypted_body
 * @property string $body_hash
 * @property string $status active|retired
 * @property int|null $created_by
 * @property-read Course $course
 */
#[Fillable(['course_id', 'unit', 'type', 'marks', 'co_code', 'bloom_level', 'difficulty'])]
#[Hidden(['encrypted_body'])]
class Question extends TenantModel
{
    public const TYPES = ['mcq', 'short', 'long', 'numerical'];

    public const DIFFICULTIES = ['easy', 'medium', 'hard'];

    public const BLOOM_LEVELS = ['remember', 'understand', 'apply', 'analyse', 'evaluate', 'create'];

    /** Only fillable fields are audited, so encrypted bodies never reach the activity log. */
    protected string $auditLog = 'qbank';

    protected function casts(): array
    {
        return ['marks' => 'decimal:2', 'unit' => 'integer'];
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
