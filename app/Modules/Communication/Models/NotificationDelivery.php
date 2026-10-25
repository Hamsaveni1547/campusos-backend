<?php

namespace App\Modules\Communication\Models;

use App\Shared\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One delivery attempt of a notification on one channel.
 *
 * @property int $id
 * @property string|null $notification_id
 * @property string $notification_type
 * @property string $channel
 * @property string $status
 * @property string|null $error
 */
#[Fillable(['institution_id', 'notification_id', 'notification_type', 'channel', 'notifiable_type', 'notifiable_id', 'status', 'error', 'created_at'])]
class NotificationDelivery extends Model
{
    use BelongsToInstitution;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
