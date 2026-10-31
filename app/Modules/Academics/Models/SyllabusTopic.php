<?php

namespace App\Modules\Academics\Models;

use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $syllabus_unit_id
 * @property string $title
 * @property string $hours
 * @property int $position
 * @property-read SyllabusUnit $unit
 */
#[Fillable(['syllabus_unit_id', 'title', 'hours', 'position'])]
class SyllabusTopic extends TenantModel
{
    protected string $auditLog = 'academics';

    protected function casts(): array
    {
        return ['hours' => 'decimal:1', 'position' => 'integer'];
    }

    /**
     * @return BelongsTo<SyllabusUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(SyllabusUnit::class, 'syllabus_unit_id');
    }
}
