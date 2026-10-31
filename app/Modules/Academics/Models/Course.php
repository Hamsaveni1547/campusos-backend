<?php

namespace App\Modules\Academics\Models;

use App\Modules\Academics\Enums\CourseType;
use App\Modules\Institution\Enums\RecordStatus;
use App\Modules\Institution\Models\Department;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * A course (subject) in the catalogue, e.g. "21CS51 Database Management Systems".
 *
 * @property int $department_id
 * @property string $code
 * @property string $title
 * @property CourseType $type
 * @property string $credits
 * @property int $lecture_hours
 * @property int $tutorial_hours
 * @property int $practical_hours
 * @property string|null $regulation
 * @property string|null $description
 * @property list<string>|null $textbooks
 * @property RecordStatus $status
 * @property-read Department $department
 */
#[Fillable(['department_id', 'code', 'title', 'type', 'credits', 'lecture_hours', 'tutorial_hours', 'practical_hours', 'regulation', 'description', 'textbooks', 'status'])]
class Course extends TenantModel
{
    protected string $auditLog = 'academics';

    protected $attributes = ['status' => 'active', 'lecture_hours' => 0, 'tutorial_hours' => 0, 'practical_hours' => 0];

    protected function casts(): array
    {
        return [
            'type' => CourseType::class,
            'status' => RecordStatus::class,
            'credits' => 'decimal:1',
            'lecture_hours' => 'integer',
            'tutorial_hours' => 'integer',
            'practical_hours' => 'integer',
            'textbooks' => 'array',
        ];
    }

    /**
     * L-T-P, e.g. "3-1-0".
     */
    public function ltp(): string
    {
        return $this->lecture_hours.'-'.$this->tutorial_hours.'-'.$this->practical_hours;
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return HasMany<SyllabusUnit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(SyllabusUnit::class)->orderBy('number');
    }

    /**
     * @return HasManyThrough<SyllabusTopic, SyllabusUnit, $this>
     */
    public function topics(): HasManyThrough
    {
        return $this->hasManyThrough(SyllabusTopic::class, SyllabusUnit::class);
    }

    /**
     * @return HasMany<CourseOffering, $this>
     */
    public function offerings(): HasMany
    {
        return $this->hasMany(CourseOffering::class);
    }

    /**
     * @return HasMany<CurriculumCourse, $this>
     */
    public function curriculumEntries(): HasMany
    {
        return $this->hasMany(CurriculumCourse::class);
    }
}
