<?php

namespace App\Modules\Timetable\Models;

use App\Modules\Academics\Models\OfferingSection;
use App\Modules\HR\Models\Employee;
use App\Modules\Institution\Models\Room;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A change on one date: a period cancelled, taken by a substitute, or an extra class added.
 *
 * @property int $timetable_id
 * @property Carbon $date
 * @property string $type cancelled|substituted|extra
 * @property int|null $timetable_entry_id
 * @property int|null $time_slot_id
 * @property int|null $offering_section_id
 * @property int|null $employee_id
 * @property int|null $room_id
 * @property string|null $reason
 * @property-read TimetableEntry|null $entry
 * @property-read TimeSlot|null $slot
 * @property-read OfferingSection|null $class
 * @property-read Employee|null $employee
 * @property-read Room|null $room
 */
#[Fillable(['timetable_id', 'date', 'type', 'timetable_entry_id', 'time_slot_id', 'offering_section_id', 'employee_id', 'room_id', 'reason'])]
class TimetableAdjustment extends TenantModel
{
    protected string $auditLog = 'timetable';

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    /**
     * @return BelongsTo<TimetableEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(TimetableEntry::class, 'timetable_entry_id');
    }

    /**
     * @return BelongsTo<TimeSlot, $this>
     */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(TimeSlot::class, 'time_slot_id');
    }

    /**
     * @return BelongsTo<OfferingSection, $this>
     */
    public function class(): BelongsTo
    {
        return $this->belongsTo(OfferingSection::class, 'offering_section_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
