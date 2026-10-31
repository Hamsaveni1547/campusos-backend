<?php

namespace App\Modules\Timetable\Models;

use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $slot_template_id
 * @property int $position
 * @property string $label
 * @property string $starts_at HH:MM:SS
 * @property string $ends_at HH:MM:SS
 * @property bool $is_break
 * @property-read SlotTemplate $template
 */
#[Fillable(['slot_template_id', 'position', 'label', 'starts_at', 'ends_at', 'is_break'])]
class TimeSlot extends TenantModel
{
    protected string $auditLog = 'timetable';

    protected function casts(): array
    {
        return ['position' => 'integer', 'is_break' => 'boolean'];
    }

    /**
     * @return BelongsTo<SlotTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(SlotTemplate::class, 'slot_template_id');
    }
}
