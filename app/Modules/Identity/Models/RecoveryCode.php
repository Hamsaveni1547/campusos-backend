<?php

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $code_hash
 * @property Carbon|null $used_at
 */
#[Fillable(['user_id', 'code_hash', 'used_at', 'used_ip'])]
class RecoveryCode extends Model
{
    protected $table = 'user_recovery_codes';

    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
        ];
    }
}
