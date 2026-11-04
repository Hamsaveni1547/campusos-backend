<?php

namespace App\Modules\Leave\Services;

use App\Models\User;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use App\Modules\Institution\Models\CalendarEvent;
use App\Modules\Leave\Models\LeaveApplication;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeaveType;
use App\Shared\Exceptions\BusinessRuleException;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;

/**
 * Staff leave: working-day counting, balances, applying, deciding and cancelling.
 */
class LeaveService
{
    /**
     * Working days between two dates: Sundays and calendar holidays are not counted.
     */
    public function workingDays(Carbon $from, Carbon $to): float
    {
        $holidays = CalendarEvent::query()->where('is_holiday', true)
            ->whereDate('starts_on', '<=', $to)->whereDate('ends_on', '>=', $from)->get()
            ->flatMap(fn (CalendarEvent $e) => collect(CarbonPeriod::create($e->starts_on, $e->ends_on))->map(fn (Carbon $d): string => $d->toDateString()))
            ->unique()->all();

        return (float) collect(CarbonPeriod::create($from, $to))
            ->reject(fn (Carbon $d): bool => $d->isSunday() || in_array($d->toDateString(), $holidays, true))
            ->count();
    }

    /**
     * Allotted, used (approved), pending and available days for each type in a year.
     *
     * @return list<array{type: LeaveType, allotted: float, used: float, pending: float, available: float|null}>
     */
    public function balances(Employee $employee, int $year): array
    {
        $allotted = LeaveBalance::query()->where('employee_id', $employee->id)->where('year', $year)->pluck('allotted', 'leave_type_id');
        $taken = LeaveApplication::query()->where('employee_id', $employee->id)->whereYear('from_date', $year)->whereIn('status', ['approved', 'pending'])
            ->get()->groupBy('leave_type_id');

        return LeaveType::query()->where('status', 'active')->orderBy('name')->get()->map(function (LeaveType $type) use ($allotted, $taken): array {
            $rows = $taken->get($type->id, collect());
            $used = (float) $rows->where('status', 'approved')->sum('days');
            $pending = (float) $rows->where('status', 'pending')->sum('days');
            $given = (float) ($allotted[$type->id] ?? 0);

            return ['type' => $type, 'allotted' => $given, 'used' => $used, 'pending' => $pending, 'available' => $type->is_paid ? $given - $used - $pending : null];
        })->values()->all();
    }

    /**
     * Gives every working employee their yearly days for each paid type (existing balances are kept).
     */
    public function allot(int $year): int
    {
        $count = 0;
        $types = LeaveType::query()->where('status', 'active')->where('is_paid', true)->get();

        foreach (Employee::query()->whereIn('status', [EmployeeStatus::Active, EmployeeStatus::OnLeave])->pluck('id') as $employeeId) {
            foreach ($types as $type) {
                $balance = LeaveBalance::query()->firstOrCreate(['employee_id' => $employeeId, 'leave_type_id' => $type->id, 'year' => $year], ['allotted' => $type->days_per_year]);
                $count += $balance->wasRecentlyCreated ? 1 : 0;
            }
        }

        return $count;
    }

    public function apply(Employee $employee, LeaveType $type, Carbon $from, Carbon $to, bool $halfDay, string $reason): LeaveApplication
    {
        if ($to->lt($from) || $from->year !== $to->year) {
            throw new BusinessRuleException('The leave must end on or after it starts, within one calendar year.', 'DATES_INVALID', 422);
        }

        if ($halfDay && (! $type->allow_half_day || ! $from->isSameDay($to))) {
            throw new BusinessRuleException('Half-day leave is for a single day of a type that allows it.', 'HALF_DAY_INVALID', 422);
        }

        $days = $this->workingDays($from, $to);

        if ($days <= 0) {
            throw new BusinessRuleException('Those dates are all Sundays or holidays.', 'NO_WORKING_DAYS', 422);
        }

        $days = $halfDay ? 0.5 : $days;
        $overlap = LeaveApplication::query()->where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])
            ->whereDate('from_date', '<=', $to)->whereDate('to_date', '>=', $from)->exists();

        if ($overlap) {
            throw new BusinessRuleException('You already have leave on some of these days.', 'LEAVE_OVERLAP', 409);
        }

        if ($type->is_paid) {
            $balance = collect($this->balances($employee, $from->year))->first(fn (array $b): bool => $b['type']->id === $type->id);

            if ($balance === null || $days > $balance['available']) {
                throw new BusinessRuleException('Only '.($balance['available'] ?? 0).' day(s) of '.$type->name.' are left.', 'INSUFFICIENT_BALANCE', 422);
            }
        }

        $application = LeaveApplication::query()->create([
            'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(), 'half_day' => $halfDay, 'days' => $days, 'reason' => $reason,
        ]);
        $application->forceFill(['status' => 'pending'])->save();

        return $application;
    }

    public function decide(LeaveApplication $application, bool $approve, ?string $remarks, User $user): void
    {
        if ($application->status !== 'pending') {
            throw new BusinessRuleException('This application has already been decided.', 'ALREADY_REVIEWED', 409);
        }

        if ($application->employee->user_id === $user->id) {
            throw new BusinessRuleException('You cannot decide your own leave.', 'SAME_PERSON', 409);
        }

        if (! $approve && blank($remarks)) {
            throw new BusinessRuleException('Say why the leave is rejected.', 'REMARKS_REQUIRED', 422);
        }

        $application->forceFill(['status' => $approve ? 'approved' : 'rejected', 'remarks' => $remarks, 'decided_by' => $user->id, 'decided_at' => now()])->save();
    }

    /**
     * The applicant withdraws pending leave, or approved leave that has not started.
     */
    public function cancel(LeaveApplication $application): void
    {
        if (! in_array($application->status, ['pending', 'approved'], true) || ($application->status === 'approved' && $application->from_date->lte(today()))) {
            throw new BusinessRuleException('Only pending leave, or approved leave that has not started, can be cancelled.', 'NOT_CANCELLABLE', 409);
        }

        $application->forceFill(['status' => 'cancelled'])->save();
    }

    /**
     * Unpaid approved leave days per employee in a month (for payroll loss of pay).
     *
     * @return array<int, float> employee id => days
     */
    public function unpaidDays(string $month): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        $end = $start->copy()->endOfMonth();

        return LeaveApplication::query()->where('status', 'approved')->whereHas('type', fn ($q) => $q->where('is_paid', false))
            ->whereDate('from_date', '<=', $end)->whereDate('to_date', '>=', $start)->get()
            ->groupBy('employee_id')
            ->map(fn ($rows): float => (float) $rows->sum(fn (LeaveApplication $a): float => $a->half_day ? 0.5 : $this->workingDays($a->from_date->max($start), $a->to_date->min($end))))
            ->all();
    }
}
