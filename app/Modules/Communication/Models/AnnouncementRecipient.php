<?php

namespace App\Modules\Communication\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person who received an announcement, with when they read and acknowledged it.
 *
 * @property int $announcement_id
 * @property int $user_id
 * @property Carbon|null $read_at
 * @property Carbon|null $acknowledged_at
 * @property-read Announcement $announcement
 * @property-read User $user
 */
class AnnouncementRecipient extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'acknowledged_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Announcement, $this>
     */
    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
