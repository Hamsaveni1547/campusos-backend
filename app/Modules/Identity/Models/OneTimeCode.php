<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Enums\OneTimeCodePurpose;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Hashed short-lived code sent by email.
 *
 * @property int $id
 * @property int $user_id
 * @property OneTimeCodePurpose $purpose
 * @property string|null $destination
 * @property string $code_hash
 * @property int $attempts
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'purpose', 'destination', 'code_hash', 'attempts', 'expires_at', 'consumed_at', 'ip_address'])]
class OneTimeCode extends Model
{
    protected function casts(): array
    {
        return [
            'purpose' => OneTimeCodePurpose::class,
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }
}
