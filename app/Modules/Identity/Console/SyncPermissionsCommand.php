<?php

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Actions\Roles\SyncSystemPermissions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('campusos:sync-permissions')]
#[Description('Create/update all module permissions and the built-in roles (run on every deploy)')]
class SyncPermissionsCommand extends Command
{
    public function handle(SyncSystemPermissions $sync): int
    {
        $result = $sync->handle();

        $this->components->info("Synced {$result['permissions']} permissions and {$result['roles']} built-in roles.");

        if ($result['removed_permissions'] > 0) {
            $this->components->warn("Removed {$result['removed_permissions']} permissions that are no longer defined.");
        }

        return self::SUCCESS;
    }
}
