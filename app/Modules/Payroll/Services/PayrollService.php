<?php

namespace App\Modules\Payroll\Services;

use App\Models\User;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Services\LeaveService;
use App\Modules\Payroll\Models\EmployeeSalary;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Support\PayrollCalculator;
use App\Shared\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Employee salaries and monthly payroll: create run → enter LOP and adjustments → calculate →
 * approve (someone else) → finalise. A finalised run cannot change.
 */
class PayrollService
{
    /**
     * Replace an employee's salary. `$amounts` maps component id => amount (null for percentage components).
     *
     * @param  array<int, float|null>  $amounts
     */
    public function setSalary(Employee $employee, array $amounts): void
    {
        $components = PayComponent::query()->whereIn('id', array_keys($amounts))->get()->keyBy('id');

        if (! $components->contains('code', 'BASIC') || ($amounts[$components->firstWhere('code', 'BASIC')->id] ?? 0) <= 0) {
            throw new BusinessRuleException('Every salary needs a basic pay amount.', 'BASIC_REQUIRED', 422);
        }

        foreach ($components as $id => $component) {
            if ($component->calculation === 'fixed' && ($amounts[$id] === null || $amounts[$id] < 0)) {
                throw new BusinessRuleException('Enter an amount for '.$component->name.'.', 'AMOUNT_REQUIRED', 422);
            }
        }

        DB::transaction(function () use ($employee, $amounts, $components): void {
            EmployeeSalary::query()->where('employee_id', $employee->id)->whereNotIn('pay_component_id', array_keys($amounts))->delete();

            foreach ($amounts as $id => $amount) {
                EmployeeSalary::query()->updateOrCreate(['employee_id' => $employee->id, 'pay_component_id' => $id], ['amount' => $components[$id]->calculation === 'fixed' ? $amount : null]);
            }
        });
    }

    /**
     * Starts a month with a payslip for every working employee who has a salary; approved unpaid
     * leave in the month is filled in as loss-of-pay days.
     */
    public function createRun(string $month): PayrollRun
    {
        if (PayrollRun::query()->where('month', $month)->exists()) {
            throw new BusinessRuleException('Payroll for this month already exists.', 'RUN_EXISTS', 409);
        }

        return DB::transaction(function () use ($month): PayrollRun {
            $run = PayrollRun::query()->create(['month' => $month]);
            $run->forceFill(['status' => 'draft'])->save();
            $employees = Employee::query()->whereIn('status', [EmployeeStatus::Active, EmployeeStatus::OnLeave])
                ->whereIn('id', EmployeeSalary::query()->select('employee_id'))->pluck('id');

            $unpaidLeave = app(LeaveService::class)->unpaidDays($month);

            foreach ($employees as $employeeId) {
                Payslip::query()->create(['payroll_run_id' => $run->id, 'employee_id' => $employeeId, 'days_in_month' => $run->daysInMonth(), 'lop_days' => min($run->daysInMonth(), $unpaidLeave[$employeeId] ?? 0)]);
            }

            return $run;
        });
    }

    /**
     * Loss-of-pay days and one-off adjustments for one employee; calculated figures are cleared.
     *
     * @param  list<array{kind: string, label: string, amount: float}>  $adjustments
     */
    public function setInputs(Payslip $payslip, float $lopDays, array $adjustments): void
    {
        $run = $payslip->run;

        if (! $run->isEditable()) {
            throw new BusinessRuleException('This payroll is approved; it can no longer change.', 'RUN_LOCKED', 409);
        }

        if ($lopDays < 0 || $lopDays > $payslip->days_in_month) {
            throw new BusinessRuleException('Loss-of-pay days must be between 0 and '.$payslip->days_in_month.'.', 'LOP_INVALID', 422);
        }

        DB::transaction(function () use ($payslip, $lopDays, $adjustments, $run): void {
            $payslip->forceFill(['lop_days' => $lopDays])->save();
            $payslip->adjustments()->delete();

            foreach ($adjustments as $adjustment) {
                $payslip->adjustments()->create($adjustment);
            }

            $run->forceFill(['status' => 'draft', 'computed_by' => null, 'computed_at' => null])->save();
        });
    }

    public function compute(PayrollRun $run, User $user): void
    {
        if (! $run->isEditable()) {
            throw new BusinessRuleException('This payroll is approved; it can no longer change.', 'RUN_LOCKED', 409);
        }

        $components = PayComponent::query()->orderBy('position')->orderBy('id')->get();
        $payslips = $run->payslips()->with(['employee.user', 'adjustments'])->get();
        $salaries = EmployeeSalary::query()->whereIn('employee_id', $payslips->pluck('employee_id'))->get()->groupBy('employee_id');
        $negative = [];

        DB::transaction(function () use ($run, $user, $components, $payslips, $salaries, &$negative): void {
            foreach ($payslips as $payslip) {
                $salary = $salaries->get($payslip->employee_id, collect())->keyBy('pay_component_id');
                $assigned = $components->filter(fn (PayComponent $c): bool => $salary->has($c->id))->map(fn (PayComponent $c): array => [
                    'code' => $c->code, 'name' => $c->name, 'kind' => $c->kind, 'calculation' => $c->calculation,
                    'percent' => $c->percent === null ? null : (float) $c->percent, 'max_amount' => $c->max_amount === null ? null : (float) $c->max_amount,
                    'affected_by_lop' => $c->affected_by_lop, 'amount' => $salary[$c->id]->amount === null ? null : (float) $salary[$c->id]->amount,
                ])->values()->all();

                $result = PayrollCalculator::compute($assigned, $payslip->days_in_month, (float) $payslip->lop_days, $payslip->adjustments->map(fn ($a): array => ['kind' => $a->kind, 'label' => $a->label, 'amount' => (float) $a->amount])->all());

                if ($result['net'] < 0) {
                    $negative[] = $payslip->employee->user->name;
                }

                $payslip->forceFill(['gross' => $result['gross'], 'deductions' => $result['deductions'], 'net' => $result['net'], 'lines' => $result['lines']])->save();
            }

            if ($negative !== []) {
                throw new BusinessRuleException('Net pay would be negative for: '.implode(', ', $negative).'. Reduce their deductions.', 'NEGATIVE_NET', 422, ['employees' => $negative]);
            }

            $run->forceFill(['status' => 'computed', 'computed_by' => $user->id, 'computed_at' => now()])->save();
        });
    }

    public function approve(PayrollRun $run, User $user): void
    {
        $this->assertStatus($run, 'computed');

        if ($run->computed_by === $user->id) {
            throw new BusinessRuleException('Someone other than the person who calculated the payroll must approve it.', 'SAME_PERSON', 409);
        }

        $run->forceFill(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now()])->save();
    }

    public function finalize(PayrollRun $run, string $paidOn, User $user): void
    {
        $this->assertStatus($run, 'approved');
        $run->forceFill(['status' => 'finalized', 'finalized_by' => $user->id, 'finalized_at' => now(), 'paid_on' => $paidOn])->save();
    }

    public function delete(PayrollRun $run): void
    {
        if (! $run->isEditable()) {
            throw new BusinessRuleException('An approved payroll cannot be deleted.', 'RUN_LOCKED', 409);
        }

        $run->delete();
    }

    private function assertStatus(PayrollRun $run, string $status): void
    {
        if ($run->status !== $status) {
            throw new BusinessRuleException('This payroll is not at that stage.', 'INVALID_STATUS', 409);
        }
    }
}
