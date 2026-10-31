<?php

namespace App\Modules\QuestionBank\Models;

use App\Modules\Academics\Models\Course;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $course_id
 * @property string $name
 * @property int $duration_minutes
 * @property list<array{name: string, type: string, marks: float, count: int, attempt: int|null, units: list<int>|null, bloom_levels: list<string>|null, difficulty: string|null}> $sections
 * @property int $avoid_recent_papers
 * @property-read Course $course
 */
#[Fillable(['course_id', 'name', 'duration_minutes', 'sections', 'avoid_recent_papers'])]
class PaperBlueprint extends TenantModel
{
    protected string $auditLog = 'qbank';

    protected function casts(): array
    {
        return ['sections' => 'array'];
    }

    /**
     * Total marks a student can score: per section, attempted questions × marks.
     */
    public function totalMarks(): float
    {
        return (float) array_sum(array_map(fn (array $s): float => (float) $s['marks'] * (int) ($s['attempt'] ?? $s['count']), $this->sections));
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * @return HasMany<QuestionPaper, $this>
     */
    public function papers(): HasMany
    {
        return $this->hasMany(QuestionPaper::class);
    }
}
