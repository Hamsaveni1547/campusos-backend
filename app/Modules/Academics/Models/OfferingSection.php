<?php

namespace App\Modules\Academics\Models;

use App\Modules\Institution\Models\Section;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A class: one offering taught to one section.
 *
 * @property int $course_offering_id
 * @property int $section_id
 * @property-read CourseOffering $offering
 * @property-read Section $section
 */
#[Fillable(['course_offering_id', 'section_id'])]
class OfferingSection extends TenantModel
{
    protected string $auditLog = 'academics';

    /**
     * @return BelongsTo<CourseOffering, $this>
     */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    /**
     * @return BelongsTo<Section, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * @return HasMany<FacultyAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(FacultyAllocation::class);
    }

    /**
     * @return HasMany<TopicCoverage, $this>
     */
    public function coverage(): HasMany
    {
        return $this->hasMany(TopicCoverage::class);
    }
}
