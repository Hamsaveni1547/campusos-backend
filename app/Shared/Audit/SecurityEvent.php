<?php

namespace App\Shared\Audit;

use App\Models\User;
use App\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only record of authentication and access-control events.
 *
 * @property int $id
 * @property string $ulid
 * @property SecurityEventType $event
 */
#[Fillable(['institution_id', 'user_id', 'actor_id', 'event', 'severity', 'identifier', 'ip_address', 'user_agent', 'trace_id', 'context', 'created_at'])]
class SecurityEvent extends Model
{
    use HasPublicId;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'event' => SecurityEventType::class,
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Security events cannot be modified.');
        });

        static::deleting(function (): void {
            if (! app()->runningInConsole()) {
                throw new LogicException('Security events cannot be deleted.');
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
