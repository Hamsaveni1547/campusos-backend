<?php

namespace App\Modules\Identity\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pending second-factor step after a correct password.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $institution_id
 * @property string $token_hash
 * @property string|null $device_name
 * @property int $attempts
 * @property Carbon $expires_at
 * @property Carbon|null $completed_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'institution_id', 'token_hash', 'device_name', 'ip_address', 'user_agent', 'attempts', 'expires_at', 'completed_at'])]
class MfaChallenge extends Model
{
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOpen(): bool
    {
        return $this->completed_at === null
            && $this->expires_at->isFuture()
            && $this->attempts < (int) config('campusos.mfa.challenge_max_attempts');
    }

    public static function findByToken(string $token): ?self
    {
        return static::query()->where('token_hash', hash('sha256', $token))->first();
    }
}
