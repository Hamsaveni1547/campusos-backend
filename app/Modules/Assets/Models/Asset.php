<?php

namespace App\Modules\Assets\Models;

use App\Modules\Institution\Models\Department;
use App\Modules\Institution\Models\Room;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $tag
 * @property string $name
 * @property string $category
 * @property int|null $department_id
 * @property int|null $room_id
 * @property string|null $make
 * @property string|null $model
 * @property string|null $serial_no
 * @property Carbon|null $purchased_on
 * @property string|null $cost
 * @property string|null $vendor
 * @property Carbon|null $warranty_until
 * @property string $status working|under_repair|condemned
 * @property-read Department|null $department
 * @property-read Room|null $room
 */
#[Fillable(['tag', 'name', 'category', 'department_id', 'room_id', 'make', 'model', 'serial_no', 'purchased_on', 'cost', 'vendor', 'warranty_until', 'status'])]
class Asset extends TenantModel
{
    public const STATUSES = ['working', 'under_repair', 'condemned'];

    protected string $auditLog = 'assets';

    protected function casts(): array
    {
        return ['purchased_on' => 'date', 'warranty_until' => 'date', 'cost' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
