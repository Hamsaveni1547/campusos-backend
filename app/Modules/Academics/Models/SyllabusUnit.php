<?php

namespace App\Modules\Academics\Models;

use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $course_id
 * @property int $number
 * @property string $title
 * @property int $hours
 * @property-read Course $course
 */
#[Fillable(['course_id', 'number', 'title', 'hours'])]
class SyllabusUnit extends TenantModel
{
    protected string $auditLog = 'academics';

    protected function casts(): array
    {
        return ['number' => 'integer', 'hours' => 'integer'];
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * @return HasMany<SyllabusTopic, $this>
     */
    public function topics(): HasMany
    {
        return $this->hasMany(SyllabusTopic::class)->orderBy('position')->orderBy('id');
    }
}
