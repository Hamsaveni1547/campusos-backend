<?php

namespace App\Modules\Identity\Models;

use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * API token = one signed-in device/session.
 *
 * @property int $id
 * @property string $name
 * @property int|null $institution_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $last_used_ip
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'institution_id',
        'ip_address',
        'user_agent',
        'last_used_ip',
    ];
}
