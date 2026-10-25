<?php

namespace App\Modules\Identity\Models;

use App\Models\User;
use App\Modules\Identity\Enums\MfaMethodType;
use App\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property MfaMethodType $type
 * @property string|null $secret
 * @property bool $is_primary
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $last_used_at
 * @property int|null $last_used_timestep
 */
#[Fillable(['user_id', 'type', 'secret', 'is_primary', 'confirmed_at', 'last_used_at', 'last_used_timestep'])]
#[Hidden(['secret'])]
class MfaMethod extends Model
{
    use HasPublicId;

    protected $table = 'user_mfa_methods';

    protected function casts(): array
    {
        return [
            'type' => MfaMethodType::class,
            'secret' => 'encrypted',
            'is_primary' => 'boolean',
            'confirmed_at' => 'datetime',
            'last_used_at' => 'datetime',
            'last_used_timestep' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<MfaMethod>  $query
     */
    public function scopeConfirmed(Builder $query): void
    {
        $query->whereNotNull('confirmed_at');
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }
}
