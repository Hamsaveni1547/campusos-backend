<?php

namespace App\Modules\Attendance\Models;

use App\Models\User;
use App\Modules\Academics\Models\OfferingSection;
use App\Modules\Timetable\Models\TimeSlot;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One meeting of a class on a date (a timetable period or an extra session).
 *
 * @property int $offering_section_id
 * @property Carbon $date
 * @property int|null $time_slot_id
 * @property string|null $starts_at
 * @property string $component
 * @property string|null $notes
 * @property int|null $marked_by
 * @property Carbon|null $marked_at
 * @property-read OfferingSection $class
 * @property-read TimeSlot|null $slot
 * @property-read User|null $marker
 */
#[Fillable(['offering_section_id', 'date', 'time_slot_id', 'starts_at', 'component', 'notes'])]
class AttendanceSession extends TenantModel
{
    protected string $auditLog = 'attendance';

    protected function casts(): array
    {
        return ['date' => 'date', 'marked_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<OfferingSection, $this>
     */
    public function class(): BelongsTo
    {
        return $this->belongsTo(OfferingSection::class, 'offering_section_id');
    }

    /**
     * @return BelongsTo<TimeSlot, $this>
     */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(TimeSlot::class, 'time_slot_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function marker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    /**
     * @return HasMany<AttendanceRecord, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }
}
