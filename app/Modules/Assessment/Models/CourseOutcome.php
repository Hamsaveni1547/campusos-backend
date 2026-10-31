<?php

namespace App\Modules\Assessment\Models;

use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * Course outcome, e.g. "CO1 — Design a normalised relational schema".
 *
 * @property int $course_id
 * @property string $code
 * @property string $description
 * @property string|null $bloom_level
 */
#[Fillable(['course_id', 'code', 'description', 'bloom_level'])]
class CourseOutcome extends TenantModel
{
    protected string $auditLog = 'assessment';
}
