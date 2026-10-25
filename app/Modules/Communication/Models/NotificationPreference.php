<?php

namespace App\Modules\Communication\Models;

use App\Modules\Communication\Enums\NotificationChannel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $user_id
 * @property string $category
 * @property NotificationChannel $channel
 * @property bool $enabled
 */
#[Fillable(['user_id', 'category', 'channel', 'enabled'])]
class NotificationPreference extends Model
{
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'enabled' => 'boolean',
        ];
    }
}
