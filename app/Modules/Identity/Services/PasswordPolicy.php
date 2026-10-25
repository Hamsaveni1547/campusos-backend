<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Models\PasswordHistory;
use App\Modules\Identity\Support\SecuritySettings;
use App\Shared\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Password strength, reuse and expiry rules from the institution security settings.
 */
class PasswordPolicy
{
    public function __construct(private SecuritySettings $settings) {}

    public function rule(?int $institutionId): Password
    {
        $rule = Password::min($this->settings->int('password_min_length', $institutionId))->max(128);

        if ($this->settings->bool('password_require_mixed_case', $institutionId)) {
            $rule->mixedCase();
        }

        if ($this->settings->bool('password_require_numbers', $institutionId)) {
            $rule->numbers();
        }

        if ($this->settings->bool('password_require_symbols', $institutionId)) {
            $rule->symbols();
        }

        return $rule->letters();
    }

    /**
     * @throws ValidationException when the password was used recently
     */
    public function assertNotReused(User $user, string $plainPassword, ?int $institutionId, string $field = 'password'): void
    {
        $historyCount = $this->settings->int('password_history_count', $institutionId);

        if (Hash::check($plainPassword, $user->password)) {
            throw ValidationException::withMessages([$field => 'The new password must be different from the current password.']);
        }

        if ($historyCount === 0) {
            return;
        }

        $recent = PasswordHistory::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit($historyCount)
            ->pluck('password_hash');

        foreach ($recent as $hash) {
            if (Hash::check($plainPassword, $hash)) {
                throw ValidationException::withMessages([$field => "You cannot reuse any of your last {$historyCount} passwords."]);
            }
        }
    }

    /**
     * Save the new password and keep the old hash in history.
     */
    public function apply(User $user, string $plainPassword, bool $mustChangeOnNextLogin = false): void
    {
        if (strlen($plainPassword) > 128) {
            throw new BusinessRuleException('Password is too long.', 'PASSWORD_TOO_LONG');
        }

        if ($user->exists && $user->password !== '') {
            PasswordHistory::query()->create([
                'user_id' => $user->id,
                'password_hash' => $user->password,
                'created_at' => now(),
            ]);

            $this->pruneHistory($user);
        }

        $user->forceFill([
            'password' => $plainPassword,
            'password_changed_at' => now(),
            'must_change_password' => $mustChangeOnNextLogin,
        ])->save();
    }

    public function isExpired(User $user, ?int $institutionId): bool
    {
        $days = $this->settings->int('password_expiry_days', $institutionId);

        if ($days === 0 || $user->password_changed_at === null) {
            return false;
        }

        return $user->password_changed_at->addDays($days)->isPast();
    }

    private function pruneHistory(User $user): void
    {
        $keepIds = PasswordHistory::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(24)
            ->pluck('id');

        PasswordHistory::query()
            ->where('user_id', $user->id)
            ->whereNotIn('id', $keepIds)
            ->delete();
    }
}
