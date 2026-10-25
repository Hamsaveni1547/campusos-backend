<?php

namespace App\Shared\Imports\Jobs;

use App\Models\User;
use App\Modules\Institution\Models\Institution;
use App\Shared\Imports\Import;
use App\Shared\Imports\ImportManager;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;

class ProcessImport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public int $importId, public ?int $institutionId) {}

    public function handle(ImportManager $manager, CurrentInstitution $currentInstitution): void
    {
        $currentInstitution->set($this->institutionId !== null ? Institution::query()->find($this->institutionId) : null);

        $import = Import::query()->find($this->importId);

        if ($import === null) {
            return;
        }

        $actor = User::query()->find($import->created_by);
        if ($actor !== null) {
            Auth::setUser($actor);
        }

        $manager->process($import);
    }
}
