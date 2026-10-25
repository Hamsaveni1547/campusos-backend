<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Enums\MfaMethodType;
use App\Modules\Identity\Models\PersonalAccessToken;
use Illuminate\Support\Carbon;

/**
 * Outcome of a sign-in step: either an access token or a pending MFA challenge.
 */
final class LoginResult
{
    /**
     * @param  array<int, MfaMethodType>  $methods
     */
    private function __construct(
        public readonly User $user,
        public readonly ?string $accessToken = null,
        public readonly ?PersonalAccessToken $tokenModel = null,
        public readonly ?string $challengeToken = null,
        public readonly ?Carbon $challengeExpiresAt = null,
        public readonly array $methods = [],
        public readonly ?string $trustedDeviceToken = null,
    ) {}

    public static function authenticated(User $user, string $accessToken, PersonalAccessToken $tokenModel, ?string $trustedDeviceToken = null): self
    {
        return new self($user, $accessToken, $tokenModel, trustedDeviceToken: $trustedDeviceToken);
    }

    /**
     * @param  array<int, MfaMethodType>  $methods
     */
    public static function challenge(User $user, string $challengeToken, Carbon $expiresAt, array $methods): self
    {
        return new self($user, challengeToken: $challengeToken, challengeExpiresAt: $expiresAt, methods: $methods);
    }

    public function requiresMfa(): bool
    {
        return $this->challengeToken !== null;
    }
}
