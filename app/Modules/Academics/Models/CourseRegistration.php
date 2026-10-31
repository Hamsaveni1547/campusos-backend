<?php

namespace App\Modules\Academics\Models;

use App\Shared\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One course in a term registration: core (from the section) or elective (chosen).
 *
 * @property int $term_registration_id
 * @property int $course_offering_id
 * @property string $type
 * @property-read CourseOffering $offering
 * @property-read TermRegistration $registration
 */
#[Fillable(['term_registration_id', 'course_offering_id', 'type'])]
class CourseRegistration extends Model
{
    use BelongsToInstitution;

    /**
     * @return BelongsTo<CourseOffering, $this>
     */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    /**
     * @return BelongsTo<TermRegistration, $this>
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(TermRegistration::class, 'term_registration_id');
    }
}
