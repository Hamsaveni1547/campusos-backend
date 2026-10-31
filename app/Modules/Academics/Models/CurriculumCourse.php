<?php

namespace App\Modules\Academics\Models;

use App\Shared\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One course placed in a term of a curriculum.
 *
 * @property int $id
 * @property int $curriculum_id
 * @property int $course_id
 * @property int $term_number
 * @property string $category
 * @property bool $is_elective
 * @property-read Course $course
 * @property-read Curriculum $curriculum
 */
#[Fillable(['curriculum_id', 'course_id', 'term_number', 'category', 'is_elective'])]
class CurriculumCourse extends Model
{
    use BelongsToInstitution;

    protected function casts(): array
    {
        return ['term_number' => 'integer', 'is_elective' => 'boolean'];
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * @return BelongsTo<Curriculum, $this>
     */
    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }
}
