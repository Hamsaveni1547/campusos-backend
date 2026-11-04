<?php

namespace Database\Seeders\Demo;

use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Models\LeaveApplication;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveService;
use App\Shared\Exceptions\BusinessRuleException;
use Database\Seeders\Support\CampusSeeder;
use Illuminate\Support\Carbon;

/**
 * Leave types, 2026 balances for every working employee, and applications: approved (past and
 * upcoming), waiting, rejected, cancelled, and one unpaid day in October (loss of pay in payroll).
 */
class LeaveSeeder extends CampusSeeder
{
    protected function seed(): void
    {
        if (LeaveType::query()->exists()) {
            return;
        }

        $leave = app(LeaveService::class);
        $hr = $this->user('hr@'.self::DOMAIN);
        $principal = $this->user('principal@'.self::DOMAIN);

        $types = $this->as($hr, function () use ($leave): array {
            $types = [];
            foreach ([['CL', 'Casual leave', 12, true, true], ['EL', 'Earned leave', 15, true, false], ['ML', 'Medical leave', 10, true, false], ['OD', 'On duty', 15, true, true], ['LOP', 'Leave without pay', 0, false, false]] as [$code, $name, $days, $paid, $half]) {
                $types[$code] = LeaveType::query()->create(['code' => $code, 'name' => $name, 'days_per_year' => $days, 'is_paid' => $paid, 'allow_half_day' => $half]);
            }
            $leave->allot(2026);

            return $types;
        });

        $staff = Employee::query()->with('user')->where('staff_type', 'teaching')->where('status', EmployeeStatus::Active)->orderBy('id')->get()->values();

        // [employee index, type, from, to, half day, reason, outcome]
        foreach ([
            [1, 'CL', '2026-09-08', '2026-09-09', false, 'Family function in Mysuru', 'approved'],
            [2, 'ML', '2026-10-05', '2026-10-07', false, 'Viral fever (certificate submitted)', 'approved'],
            [3, 'LOP', '2026-10-14', '2026-10-14', false, 'Personal work', 'approved'],
            [4, 'OD', '2026-10-21', '2026-10-21', false, 'Resource person at VTU workshop', 'approved'],
            [5, 'CL', '2026-10-28', '2026-10-28', true, 'Bank work', 'rejected'],
            [6, 'EL', '2026-12-21', '2026-12-26', false, 'Year-end vacation', 'approved'],
            [7, 'CL', now()->addDays(5)->toDateString(), now()->addDays(6)->toDateString(), false, 'Sister’s wedding', 'pending'],
            [8, 'OD', now()->addDays(9)->toDateString(), now()->addDays(9)->toDateString(), false, 'Judge at inter-college hackathon', 'pending'],
            [9, 'CL', now()->addDays(12)->toDateString(), now()->addDays(12)->toDateString(), false, 'House shifting', 'cancelled'],
        ] as [$i, $code, $from, $to, $half, $reason, $outcome]) {
            $employee = $staff[$i % $staff->count()];

            try {
                $application = $this->as($employee->user, fn () => $leave->apply($employee, $types[$code], Carbon::parse($from), Carbon::parse($to), $half, $reason));
            } catch (BusinessRuleException) {
                continue; // e.g. all holidays
            }

            $application->forceFill(['created_at' => Carbon::parse($from)->subDays(4)])->save();

            match ($outcome) {
                'approved' => $this->as($principal, fn () => $leave->decide($application, true, null, $this->fresh($principal))),
                'rejected' => $this->as($principal, fn () => $leave->decide($application, false, 'Internal assessment duty that day', $this->fresh($principal))),
                'cancelled' => $leave->cancel($application),
                default => null,
            };
        }

        LeaveApplication::query()->whereNotNull('decided_at')->each(fn (LeaveApplication $a) => $a->forceFill(['decided_at' => $a->created_at?->copy()->addDay()])->save());
    }
}
