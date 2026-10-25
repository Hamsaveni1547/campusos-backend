<?php

namespace App\Modules\Institution\Models;

use App\Modules\Institution\Enums\RecordStatus;
use App\Modules\Institution\Enums\RoomType;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Classroom, laboratory, hall or office.
 *
 * @property int|null $department_id
 * @property string $code
 * @property RoomType $type
 * @property int $capacity
 * @property RecordStatus $status
 * @property-read Department|null $department
 */
#[Fillable(['department_id', 'code', 'name', 'building', 'floor', 'type', 'capacity', 'status'])]
class Room extends TenantModel
{
    protected string $auditLog = 'institution';

    protected $attributes = ['status' => 'active', 'capacity' => 0];

    protected function casts(): array
    {
        return [
            'type' => RoomType::class,
            'status' => RecordStatus::class,
            'capacity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
