<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Models\TrustedDevice;
use App\Modules\Identity\Support\SecuritySettings;
use App\Shared\Http\RequestContext;
use Illuminate\Support\Str;

/**
 * "Remember this device" after a successful second factor.
 */
class TrustedDeviceService
{
    public function __construct(
        private SecuritySettings $settings,
        private RequestContext $context,
    ) {}

    public function isEnabled(?int $institutionId): bool
    {
        return $this->settings->int('trusted_device_days', $institutionId) > 0;
    }

    /**
     * @return array{device: TrustedDevice, token: string}
     */
    public function trust(User $user, ?string $name, ?int $institutionId): array
    {
        $token = Str::random(64);

        $device = TrustedDevice::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'name' => $name !== null ? Str::limit($name, 250, '') : null,
            'ip_address' => $this->context->ip(),
            'user_agent' => $this->context->userAgent(),
            'last_used_at' => now(),
            'expires_at' => now()->addDays($this->settings->int('trusted_device_days', $institutionId)),
        ]);

        return ['device' => $device, 'token' => $token];
    }

    public function check(User $user, ?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        $device = TrustedDevice::query()
            ->where('user_id', $user->id)
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();

        if ($device === null) {
            return false;
        }

        $device->forceFill(['last_used_at' => now(), 'ip_address' => $this->context->ip()])->save();

        return true;
    }

    public function revokeAll(User $user): int
    {
        return TrustedDevice::query()->where('user_id', $user->id)->delete();
    }
}
