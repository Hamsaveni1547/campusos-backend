<?php

namespace App\Modules\Communication\Models;

use App\Modules\Communication\Enums\NotificationChannel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Institution override of a built-in template. institution_id NULL = platform override.
 *
 * @property int $id
 * @property int|null $institution_id
 * @property string $template_key
 * @property NotificationChannel $channel
 * @property string|null $subject
 * @property string $body
 * @property bool $is_active
 */
#[Fillable(['institution_id', 'template_key', 'channel', 'subject', 'body', 'is_active', 'updated_by'])]
class NotificationTemplate extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('communication')
            ->logOnly(['template_key', 'channel', 'subject', 'body', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
