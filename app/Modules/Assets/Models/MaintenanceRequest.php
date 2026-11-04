<?php

namespace App\Modules\Assets\Models;

use App\Models\User;
use App\Modules\Institution\Models\Room;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int|null $asset_id
 * @property int|null $room_id
 * @property string $title
 * @property string|null $description
 * @property string $priority low|medium|high
 * @property string $status open|in_progress|resolved|closed
 * @property string|null $assigned_to technician or vendor
 * @property string|null $resolution
 * @property string|null $cost
 * @property Carbon|null $resolved_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property-read Asset|null $asset
 * @property-read Room|null $room
 * @property-read User|null $raiser
 */
#[Fillable(['asset_id', 'room_id', 'title', 'description', 'priority'])]
class MaintenanceRequest extends TenantModel
{
    protected string $auditLog = 'assets';

    protected function casts(): array
    {
        return ['cost' => 'decimal:2', 'resolved_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
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
    public function raiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
