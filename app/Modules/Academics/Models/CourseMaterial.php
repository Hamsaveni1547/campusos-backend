<?php

namespace App\Modules\Academics\Models;

use App\Shared\Files\StoredFile;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Notes, slides or a link shared with the students of an offering.
 *
 * @property int $course_offering_id
 * @property int|null $syllabus_unit_id
 * @property string $title
 * @property int|null $file_id
 * @property string|null $url
 * @property bool $is_published
 * @property Carbon|null $created_at
 * @property int|null $created_by
 * @property-read StoredFile|null $file
 * @property-read SyllabusUnit|null $unit
 */
#[Fillable(['course_offering_id', 'syllabus_unit_id', 'title', 'file_id', 'url', 'is_published'])]
class CourseMaterial extends TenantModel
{
    protected string $auditLog = 'academics';

    protected $attributes = ['is_published' => true];

    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'file_id');
    }

    /**
     * @return BelongsTo<SyllabusUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(SyllabusUnit::class, 'syllabus_unit_id');
    }
}
