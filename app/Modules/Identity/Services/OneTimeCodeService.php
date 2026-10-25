<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Enums\OneTimeCodeChannel;
use App\Modules\Identity\Enums\OneTimeCodePurpose;
use App\Modules\Identity\Models\OneTimeCode;
use App\Modules\Identity\Notifications\OneTimeCodeNotification;
use App\Modules\Identity\Support\SecuritySettings;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Http\RequestContext;
use Illuminate\Support\Facades\Hash;

/**
 * Issues and checks 6-digit codes sent by email or SMS. Codes are stored hashed.
 */
class OneTimeCodeService
{
    private const MAX_CODES_PER_HOUR = 10;

    public function __construct(
        private SecuritySettings $settings,
        private RequestContext $context,
    ) {}

    public function issue(User $user, OneTimeCodePurpose $purpose, OneTimeCodeChannel $channel, ?int $institutionId, ?string $destination = null): OneTimeCode
    {
        $destination ??= $channel === OneTimeCodeChannel::Sms ? $user->mobile : $user->email;

        if (blank($destination)) {
            throw new BusinessRuleException('No destination is available for this verification method.', 'OTP_DESTINATION_MISSING');
        }

        $this->guardSendRate($user, $purpose, $channel, $institutionId);

        OneTimeCode::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->where('channel', $channel)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $ttl = $this->settings->int('otp_ttl_minutes', $institutionId);

        $record = OneTimeCode::query()->create([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'channel' => $channel,
            'destination' => $destination,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes($ttl),
            'ip_address' => $this->context->ip(),
        ]);

        $notifiable = $destination === ($channel === OneTimeCodeChannel::Sms ? $user->mobile : $user->email)
            ? $user
            : (clone $user)->forceFill([$channel === OneTimeCodeChannel::Sms ? 'mobile' : 'email' => $destination]);

        $notifiable->notify(new OneTimeCodeNotification($code, $purpose, $channel, $ttl));

        return $record;
    }

    /**
     * Check a code. Wrong guesses count against the code; the code is consumed on success.
     */
    public function verify(User $user, OneTimeCodePurpose $purpose, OneTimeCodeChannel $channel, string $code, ?int $institutionId): bool
    {
        $record = OneTimeCode::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->where('channel', $channel)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if ($record === null || ! $record->isUsable()) {
            return false;
        }

        $maxAttempts = $this->settings->int('otp_max_attempts', $institutionId);

        if ($record->attempts >= $maxAttempts) {
            $record->forceFill(['consumed_at' => now()])->save();

            return false;
        }

        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (! Hash::check($code, $record->code_hash)) {
            $record->increment('attempts');

            return false;
        }

        $record->forceFill(['consumed_at' => now()])->save();

        return true;
    }

    public function latestDestination(User $user, OneTimeCodePurpose $purpose, OneTimeCodeChannel $channel): ?string
    {
        return OneTimeCode::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->where('channel', $channel)
            ->latest('id')
            ->value('destination');
    }

    private function guardSendRate(User $user, OneTimeCodePurpose $purpose, OneTimeCodeChannel $channel, ?int $institutionId): void
    {
        $cooldown = $this->settings->int('otp_resend_cooldown_seconds', $institutionId);

        $last = OneTimeCode::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->where('channel', $channel)
            ->latest('id')
            ->first();

        if ($last !== null && $last->created_at->gt(now()->subSeconds($cooldown))) {
            $wait = (int) ceil($last->created_at->addSeconds($cooldown)->diffInSeconds(now(), true));

            throw new BusinessRuleException("Please wait {$wait} seconds before requesting a new code.", 'OTP_RESEND_TOO_SOON', 429, ['retry_after_seconds' => $wait]);
        }

        $sentLastHour = OneTimeCode::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>', now()->subHour())
            ->count();

        if ($sentLastHour >= self::MAX_CODES_PER_HOUR) {
            throw new BusinessRuleException('Too many codes requested. Please try again in an hour.', 'OTP_LIMIT_REACHED', 429);
        }
    }
}
