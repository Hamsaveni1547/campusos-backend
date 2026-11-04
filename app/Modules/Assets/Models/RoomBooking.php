<?php

namespace App\Modules\Assets\Models;

use App\Models\User;
use App\Modules\Institution\Models\Room;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A one-off booking of a lab or room (extra sessions, project work, events).
 *
 * @property int $room_id
 * @property Carbon $date
 * @property string $starts_at
 * @property string $ends_at
 * @property string $purpose
 * @property string $status booked|cancelled
 * @property int|null $created_by
 * @property-read Room $room
 * @property-read User|null $booker
 */
#[Fillable(['room_id', 'date', 'starts_at', 'ends_at', 'purpose'])]
class RoomBooking extends TenantModel
{
    protected string $auditLog = 'assets';

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function booker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
