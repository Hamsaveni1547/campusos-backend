<?php

namespace App\Modules\Identity\Actions\Users;

use App\Models\User;

class UpdateUser
{
    /**
     * @param  array{name?: string, email?: string, mobile?: string|null, username?: string|null, locale?: string, timezone?: string|null}  $attributes
     */
    public function handle(User $user, array $attributes): User
    {
        if (isset($attributes['email'])) {
            $attributes['email'] = mb_strtolower(trim($attributes['email']));

            if ($attributes['email'] !== $user->email) {
                $user->email_verified_at = null;
            }
        }

        if (array_key_exists('mobile', $attributes) && $attributes['mobile'] !== $user->mobile) {
            $user->mobile_verified_at = null;
        }

        $user->fill($attributes)->save();

        return $user;
    }
}
