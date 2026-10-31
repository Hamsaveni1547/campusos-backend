<?php

namespace App\Modules\Timetable\Models;

use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The day's periods and breaks, e.g. "Regular day: 8 periods, Monday–Saturday".
 *
 * @property string $name
 * @property list<int> $working_days 1 = Monday … 7 = Sunday
 * @property bool $is_default
 */
#[Fillable(['name', 'working_days', 'is_default'])]
class SlotTemplate extends TenantModel
{
    protected string $auditLog = 'timetable';

    protected function casts(): array
    {
        return ['working_days' => 'array', 'is_default' => 'boolean'];
    }

    /**
     * @return HasMany<TimeSlot, $this>
     */
    public function slots(): HasMany
    {
        return $this->hasMany(TimeSlot::class)->orderBy('position');
    }

    /**
     * @return HasMany<Timetable, $this>
     */
    public function timetables(): HasMany
    {
        return $this->hasMany(Timetable::class);
    }
}
