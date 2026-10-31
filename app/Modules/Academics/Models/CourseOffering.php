<?php

namespace App\Modules\Academics\Models;

use App\Modules\Institution\Enums\RecordStatus;
use App\Modules\Institution\Models\Term;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A course taught in a term. The key every teaching module uses (timetable, attendance, marks).
 *
 * @property int $course_id
 * @property int $term_id
 * @property int|null $curriculum_id
 * @property bool $is_elective
 * @property int|null $capacity
 * @property RecordStatus $status
 * @property-read Course $course
 * @property-read Term $term
 */
#[Fillable(['course_id', 'term_id', 'curriculum_id', 'is_elective', 'capacity', 'status'])]
class CourseOffering extends TenantModel
{
    protected string $auditLog = 'academics';

    protected $attributes = ['status' => 'active', 'is_elective' => false];

    protected function casts(): array
    {
        return ['status' => RecordStatus::class, 'is_elective' => 'boolean', 'capacity' => 'integer'];
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * @return BelongsTo<Term, $this>
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /**
     * @return HasMany<OfferingSection, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(OfferingSection::class);
    }

    /**
     * @return HasMany<CourseMaterial, $this>
     */
    public function materials(): HasMany
    {
        return $this->hasMany(CourseMaterial::class)->latest('id');
    }
}
