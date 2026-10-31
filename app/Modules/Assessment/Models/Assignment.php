<?php

namespace App\Modules\Assessment\Models;

use App\Modules\Academics\Models\CourseOffering;
use App\Shared\Files\StoredFile;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $course_offering_id
 * @property int|null $assessment_id marks flow into this assessment when set
 * @property string $title
 * @property string|null $instructions
 * @property int|null $file_id
 * @property Carbon $due_at
 * @property string $max_marks
 * @property bool $allow_late
 * @property-read CourseOffering $offering
 * @property-read Assessment|null $assessment
 * @property-read StoredFile|null $file
 * @property-read int $submissions_count
 * @property-read int $evaluated_count
 */
#[Fillable(['course_offering_id', 'assessment_id', 'title', 'instructions', 'file_id', 'due_at', 'max_marks', 'allow_late'])]
class Assignment extends TenantModel
{
    protected string $auditLog = 'assessment';

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'max_marks' => 'decimal:2', 'allow_late' => 'boolean'];
    }

    /**
     * @return BelongsTo<CourseOffering, $this>
     */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    /**
     * @return BelongsTo<Assessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'file_id');
    }

    /**
     * @return HasMany<AssignmentSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class);
    }
}
