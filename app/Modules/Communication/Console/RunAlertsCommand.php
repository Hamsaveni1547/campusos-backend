<?php

namespace App\Modules\Communication\Console;

use App\Modules\Communication\Services\CampusAlerts;
use App\Modules\Institution\Models\Institution;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Console\Command;

/**
 * Scheduled alerts for every institution: due announcements, weekly attendance shortage, fee reminders.
 */
class RunAlertsCommand extends Command
{
    protected $signature = 'campusos:alerts {job : announcements, attendance or fees}';

    protected $description = 'Publish due announcements, or send attendance-shortage or fee reminders';

    public function handle(CurrentInstitution $current, CampusAlerts $alerts): int
    {
        $job = (string) $this->argument('job');
        $method = ['announcements' => 'publishDueAnnouncements', 'attendance' => 'attendanceShortage', 'fees' => 'feeReminders'][$job] ?? null;

        if ($method === null) {
            $this->error('Unknown job. Use announcements, attendance or fees.');

            return self::INVALID;
        }

        $total = 0;
        Institution::query()->each(function (Institution $institution) use ($current, $alerts, $method, &$total): void {
            $total += (int) $current->runAs($institution, fn (): int => $alerts->{$method}());
        });

        $this->info("{$job}: {$total} sent.");

        return self::SUCCESS;
    }
}
