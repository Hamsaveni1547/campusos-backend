<?php

namespace App\Modules\Assessment\Models;

use App\Modules\Academics\Models\CourseOffering;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A part of the internal marks scheme, e.g. "IA tests: best 2 of 3, worth 30".
 *
 * @property int $course_offering_id
 * @property string $name
 * @property int|null $best_of
 * @property string $weight
 * @property int $position
 * @property-read CourseOffering $offering
 */
#[Fillable(['course_offering_id', 'name', 'best_of', 'weight', 'position'])]
class AssessmentGroup extends TenantModel
{
    protected string $auditLog = 'assessment';

    protected function casts(): array
    {
        return ['best_of' => 'integer', 'weight' => 'decimal:2', 'position' => 'integer'];
    }

    /**
     * @return BelongsTo<CourseOffering, $this>
     */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    /**
     * @return HasMany<Assessment, $this>
     */
    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class)->orderBy('held_on')->orderBy('id');
    }
}
