<?php

namespace App\Modules\Admissions\Console;

use App\Modules\Admissions\Services\AdmissionService;
use App\Modules\Institution\Models\Institution;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Console\Command;

/**
 * Expires offers that were not accepted in time and gives the seats back.
 */
class ExpireAdmissionOffers extends Command
{
    protected $signature = 'admissions:expire-offers';

    protected $description = 'Expire admission offers past their deadline and release the seats';

    public function handle(CurrentInstitution $currentInstitution, AdmissionService $admissions): int
    {
        $total = 0;

        Institution::query()->each(function (Institution $institution) use ($currentInstitution, $admissions, &$total): void {
            $total += (int) $currentInstitution->runAs($institution, fn (): int => $admissions->expireDueOffers());
        });

        $this->info("Expired {$total} offer(s).");

        return self::SUCCESS;
    }
}
