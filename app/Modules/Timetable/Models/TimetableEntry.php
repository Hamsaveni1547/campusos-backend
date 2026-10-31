<?php

namespace App\Modules\Timetable\Models;

use App\Modules\Academics\Models\OfferingSection;
use App\Modules\HR\Models\Employee;
use App\Modules\Institution\Models\Room;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One period in a section's week: which class, teacher and room.
 *
 * @property int $timetable_id
 * @property int $day_of_week 1 = Monday … 7 = Sunday
 * @property int $time_slot_id
 * @property int $offering_section_id
 * @property int|null $employee_id
 * @property int|null $room_id
 * @property string $component
 * @property-read Timetable $timetable
 * @property-read TimeSlot $slot
 * @property-read OfferingSection $class
 * @property-read Employee|null $employee
 * @property-read Room|null $room
 */
#[Fillable(['timetable_id', 'day_of_week', 'time_slot_id', 'offering_section_id', 'employee_id', 'room_id', 'component'])]
class TimetableEntry extends TenantModel
{
    protected string $auditLog = 'timetable';

    protected function casts(): array
    {
        return ['day_of_week' => 'integer'];
    }

    /**
     * @return BelongsTo<Timetable, $this>
     */
    public function timetable(): BelongsTo
    {
        return $this->belongsTo(Timetable::class);
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
