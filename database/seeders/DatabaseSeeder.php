<?php

namespace Database\Seeders;

use App\Modules\Identity\Actions\Roles\SyncSystemPermissions;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Permissions and built-in roles are always seeded. Demo data only outside production.
     */
    public function run(SyncSystemPermissions $syncPermissions): void
    {
        $syncPermissions->handle();

        if (! app()->isProduction()) {
            $this->call(DemoSeeder::class);
        }
    }
}
