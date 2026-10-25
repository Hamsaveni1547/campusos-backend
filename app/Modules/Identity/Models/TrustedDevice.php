<?php

namespace App\Modules\Identity\Models;

use App\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Device allowed to skip the second factor until it expires.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property string|null $name
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $last_used_at
 * @property Carbon $expires_at
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'token_hash', 'name', 'ip_address', 'user_agent', 'last_used_at', 'expires_at'])]
#[Hidden(['token_hash'])]
class TrustedDevice extends Model
{
    use HasPublicId;

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
