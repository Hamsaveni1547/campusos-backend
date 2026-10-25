<?php

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Authorization\SystemRole;
use App\Shared\Settings\SettingsRegistry;
use App\Shared\Settings\SettingsRepository;

/**
 * Typed access to the "security" settings group.
 */
class SecuritySettings
{
    public const GROUP = 'security';

    public function __construct(private SettingsRepository $settings) {}

    public static function register(SettingsRegistry $registry): void
    {
        $registry->register(self::GROUP, 'Authentication & security', [
            'password_min_length' => ['type' => 'int', 'default' => 10, 'rules' => ['integer', 'min:8', 'max:128'], 'label' => 'Minimum password length'],
            'password_require_mixed_case' => ['type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'label' => 'Require upper and lower case letters'],
            'password_require_numbers' => ['type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'label' => 'Require a number'],
            'password_require_symbols' => ['type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'label' => 'Require a symbol'],
            'password_history_count' => ['type' => 'int', 'default' => 5, 'rules' => ['integer', 'min:0', 'max:24'], 'label' => 'Previous passwords that cannot be reused'],
            'password_expiry_days' => ['type' => 'int', 'default' => 0, 'rules' => ['integer', 'min:0', 'max:365'], 'label' => 'Password expiry in days (0 = never)'],
            'max_login_attempts' => ['type' => 'int', 'default' => 5, 'rules' => ['integer', 'min:3', 'max:20'], 'label' => 'Failed attempts before lockout'],
            'lockout_minutes' => ['type' => 'int', 'default' => 15, 'rules' => ['integer', 'min:1', 'max:1440'], 'label' => 'Lockout duration in minutes'],
            'session_lifetime_minutes' => ['type' => 'int', 'default' => 720, 'rules' => ['integer', 'min:15', 'max:43200'], 'label' => 'Maximum session length in minutes'],
            'idle_timeout_minutes' => ['type' => 'int', 'default' => 120, 'rules' => ['integer', 'min:0', 'max:1440'], 'label' => 'Sign out after inactivity in minutes (0 = off)'],
            'mfa_required_roles' => [
                'type' => 'array',
                'default' => SystemRole::mfaRequiredByDefault(),
                'rules' => ['array'],
                'item_rules' => ['string', 'max:125'],
                'label' => 'Roles that must use two-step verification',
            ],
            'otp_ttl_minutes' => ['type' => 'int', 'default' => 5, 'rules' => ['integer', 'min:1', 'max:30'], 'label' => 'One-time code validity in minutes'],
            'otp_max_attempts' => ['type' => 'int', 'default' => 5, 'rules' => ['integer', 'min:3', 'max:10'], 'label' => 'Wrong code attempts allowed'],
            'otp_resend_cooldown_seconds' => ['type' => 'int', 'default' => 60, 'rules' => ['integer', 'min:15', 'max:600'], 'label' => 'Wait before a new code can be sent (seconds)'],
            'trusted_device_days' => ['type' => 'int', 'default' => 30, 'rules' => ['integer', 'min:0', 'max:90'], 'label' => 'Remember a verified device for days (0 = off)'],
            'notify_new_device_sign_in' => ['type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'label' => 'Alert users about sign-ins from new devices'],
            'allow_applicant_registration' => ['type' => 'bool', 'default' => true, 'rules' => ['boolean'], 'label' => 'Allow applicants to register themselves'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(?int $institutionId): array
    {
        return $this->settings->group(self::GROUP, $institutionId);
    }

    public function int(string $key, ?int $institutionId): int
    {
        return (int) $this->settings->get(self::GROUP, $key, $institutionId);
    }

    public function bool(string $key, ?int $institutionId): bool
    {
        return (bool) $this->settings->get(self::GROUP, $key, $institutionId);
    }

    /**
     * @return array<int, string>
     */
    public function mfaRequiredRoles(?int $institutionId): array
    {
        return (array) $this->settings->get(self::GROUP, 'mfa_required_roles', $institutionId);
    }
}
