<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Models\RecoveryCode;
use App\Shared\Http\RequestContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Single-use backup codes for when the user has no access to their second factor.
 */
class RecoveryCodeService
{
    public function __construct(private RequestContext $context) {}

    /**
     * Replace all codes. Returns the plain codes; they are shown to the user once.
     *
     * @return array<int, string>
     */
    public function regenerate(User $user): array
    {
        $codes = collect(range(1, (int) config('campusos.mfa.recovery_codes_count')))
            ->map(fn (): string => Str::upper(Str::random(5).'-'.Str::random(5)))
            ->all();

        DB::transaction(function () use ($user, $codes): void {
            RecoveryCode::query()->where('user_id', $user->id)->delete();

            foreach ($codes as $code) {
                RecoveryCode::query()->create([
                    'user_id' => $user->id,
                    'code_hash' => Hash::make($this->normalize($code)),
                ]);
            }
        });

        return $codes;
    }

    public function consume(User $user, string $code): bool
    {
        $normalized = $this->normalize($code);

        $candidates = RecoveryCode::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->get();

        foreach ($candidates as $candidate) {
            if (Hash::check($normalized, $candidate->code_hash)) {
                $candidate->forceFill(['used_at' => now(), 'used_ip' => $this->context->ip()])->save();

                return true;
            }
        }

        return false;
    }

    public function remaining(User $user): int
    {
        return RecoveryCode::query()->where('user_id', $user->id)->whereNull('used_at')->count();
    }

    public function clear(User $user): void
    {
        RecoveryCode::query()->where('user_id', $user->id)->delete();
    }

    private function normalize(string $code): string
    {
        return Str::upper(str_replace([' ', '-'], '', $code));
    }
}
