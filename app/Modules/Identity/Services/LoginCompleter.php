<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Notifications\NewDeviceSignInNotification;
use App\Modules\Identity\Support\SecuritySettings;
use App\Shared\Audit\SecurityEvent;
use App\Shared\Audit\SecurityEventType;
use App\Shared\Audit\SecurityLogger;
use App\Shared\Http\RequestContext;

/**
 * Final step of every successful sign-in: token, login stamps, audit, new-device alert.
 */
class LoginCompleter
{
    public function __construct(
        private AccessTokenIssuer $tokens,
        private SecurityLogger $securityLogger,
        private SecuritySettings $settings,
        private RequestContext $context,
    ) {}

    public function complete(User $user, string $deviceName, ?int $institutionId, ?string $trustedDeviceToken = null, bool $viaMfa = false): LoginResult
    {
        $isNewDevice = $this->isNewDevice($user);

        $issued = $this->tokens->issue($user, $deviceName, $institutionId);

        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $this->context->ip(),
        ])->save();

        $this->securityLogger->log(
            SecurityEventType::LoginSucceeded,
            $user,
            ['device' => $deviceName, 'mfa' => $viaMfa],
            institutionId: $institutionId,
        );

        if ($isNewDevice && $this->settings->bool('notify_new_device_sign_in', $institutionId)) {
            $user->notify(new NewDeviceSignInNotification([
                'ip_address' => $this->context->ip(),
                'device' => $deviceName,
            ]));
        }

        return LoginResult::authenticated($user, $issued['token'], $issued['model'], $trustedDeviceToken);
    }

    /**
     * A device is "new" when no earlier successful sign-in used the same IP and user agent.
     * The very first sign-in of an account does not trigger an alert.
     */
    private function isNewDevice(User $user): bool
    {
        $previous = SecurityEvent::query()
            ->where('user_id', $user->id)
            ->where('event', SecurityEventType::LoginSucceeded);

        if (! (clone $previous)->exists()) {
            return false;
        }

        return ! $previous
            ->where('ip_address', $this->context->ip())
            ->where('user_agent', $this->context->userAgent())
            ->exists();
    }
}
