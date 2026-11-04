<?php

namespace App\Modules\Feedback\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $complaint_id
 * @property int|null $user_id
 * @property string $body
 * @property bool $is_internal staff-only note
 * @property Carbon $created_at
 * @property-read User|null $user
 */
#[Fillable(['complaint_id', 'user_id', 'body', 'is_internal'])]
class ComplaintMessage extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['is_internal' => 'boolean', 'created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
