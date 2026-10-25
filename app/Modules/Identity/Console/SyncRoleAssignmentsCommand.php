<?php

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Identity\Services\RoleSynchronizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('campusos:sync-role-assignments {--all : Re-sync every assignment, not only recently started/ended ones}')]
#[Description('Apply role assignments whose start or end date has passed')]
class SyncRoleAssignmentsCommand extends Command
{
    public function handle(RoleSynchronizer $synchronizer): int
    {
        $windowStart = now()->subMinutes(30);

        $assignments = RoleAssignment::query()
            ->withoutGlobalScopes()
            ->with('user')
            ->when(! $this->option('all'), fn ($query) => $query->where(function ($query) use ($windowStart): void {
                $query->whereBetween('valid_until', [$windowStart, now()])
                    ->orWhereBetween('valid_from', [$windowStart, now()]);
            }))
            ->get()
            ->unique(fn (RoleAssignment $assignment): string => $assignment->user_id.'|'.$assignment->institution_id);

        foreach ($assignments as $assignment) {
            $synchronizer->sync($assignment->user, $assignment->institution_id);
        }

        $this->components->info("Synced roles for {$assignments->count()} user(s).");

        return self::SUCCESS;
    }
}
