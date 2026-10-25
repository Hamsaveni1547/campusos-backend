<?php

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Models\MfaChallenge;
use App\Modules\Identity\Models\OneTimeCode;
use App\Modules\Identity\Models\TrustedDevice;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('campusos:prune-auth-data')]
#[Description('Delete expired one-time codes, MFA challenges and trusted devices')]
class PruneAuthDataCommand extends Command
{
    public function handle(): int
    {
        $cutoff = now()->subDay();

        $codes = OneTimeCode::query()->where('expires_at', '<', $cutoff)->delete();
        $challenges = MfaChallenge::query()->where('expires_at', '<', $cutoff)->delete();
        $devices = TrustedDevice::query()->where('expires_at', '<', now())->delete();

        $this->components->info("Pruned {$codes} codes, {$challenges} challenges and {$devices} trusted devices.");

        return self::SUCCESS;
    }
}
