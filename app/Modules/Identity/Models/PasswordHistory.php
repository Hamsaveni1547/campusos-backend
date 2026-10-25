<?php

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $password_hash
 */
#[Fillable(['user_id', 'password_hash', 'created_at'])]
class PasswordHistory extends Model
{
    public const UPDATED_AT = null;
}
