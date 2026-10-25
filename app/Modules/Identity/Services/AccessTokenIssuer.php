<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Models\PersonalAccessToken;
use App\Modules\Identity\Support\SecuritySettings;
use App\Shared\Http\RequestContext;
use Illuminate\Support\Str;

/**
 * Creates the API token that represents one signed-in device.
 */
class AccessTokenIssuer
{
    public function __construct(
        private SecuritySettings $settings,
        private RequestContext $context,
    ) {}

    /**
     * @return array{token: string, model: PersonalAccessToken}
     */
    public function issue(User $user, string $deviceName, ?int $institutionId): array
    {
        $lifetime = $this->settings->int('session_lifetime_minutes', $institutionId);

        $newToken = $user->createToken(
            Str::limit($deviceName !== '' ? $deviceName : 'Unknown device', 250, ''),
            ['*'],
            now()->addMinutes($lifetime),
        );

        /** @var PersonalAccessToken $model */
        $model = $newToken->accessToken;
        $model->forceFill([
            'institution_id' => $institutionId,
            'ip_address' => $this->context->ip(),
            'user_agent' => $this->context->userAgent(),
            'last_used_ip' => $this->context->ip(),
        ])->save();

        return ['token' => $newToken->plainTextToken, 'model' => $model];
    }
}
