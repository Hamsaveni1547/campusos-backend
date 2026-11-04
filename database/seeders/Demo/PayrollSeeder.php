<?php

namespace Database\Seeders\Demo;

use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\PayrollService;
use Database\Seeders\Support\CampusSeeder;

/**
 * Standard pay components, a salary for every working employee by designation, September 2026
 * finalised (payslips visible to staff) and October 2026 calculated, waiting for the principal.
 */
class PayrollSeeder extends CampusSeeder
{
    /** Basic pay by designation. */
    private const BASIC = [
        'Principal' => 140000, 'Professor' => 120000, 'Associate Professor' => 90000, 'Assistant Professor' => 60000,
        'Registrar' => 75000, 'Office Superintendent' => 45000, 'Accounts Officer' => 50000, 'Librarian' => 48000,
        'Lab Instructor' => 30000, 'First Division Assistant' => 28000,
    ];

    protected function seed(): void
    {
        if (PayComponent::query()->exists()) {
            return;
        }

        $payroll = app(PayrollService::class);
        $accounts = $this->user('accounts@'.self::DOMAIN);
        $principal = $this->user('principal@'.self::DOMAIN);

        $this->as($accounts, function () use ($payroll, $accounts, $principal): void {
            $components = collect([
                ['BASIC', 'Basic pay', 'earning', 'fixed', null, null, true, 1],
                ['DA', 'Dearness allowance', 'earning', 'percent', 50, null, true, 2],
                ['HRA', 'House rent allowance', 'earning', 'percent', 24, null, true, 3],
                ['CONV', 'Conveyance allowance', 'earning', 'fixed', null, null, true, 4],
                ['PF', 'Provident fund', 'deduction', 'percent', 12, 1800, false, 10],
                ['PT', 'Professional tax', 'deduction', 'fixed', null, null, false, 11],
            ])->mapWithKeys(fn (array $c): array => [$c[0] => PayComponent::query()->create([
                'code' => $c[0], 'name' => $c[1], 'kind' => $c[2], 'calculation' => $c[3], 'percent' => $c[4], 'max_amount' => $c[5], 'affected_by_lop' => $c[6], 'position' => $c[7],
            ])->id]);

            foreach (Employee::query()->with('designation')->whereIn('status', [EmployeeStatus::Active, EmployeeStatus::OnLeave])->get() as $employee) {
                $basic = self::BASIC[$employee->designation->name ?? ''] ?? 30000;
                $payroll->setSalary($employee, [
                    $components['BASIC'] => (float) $basic, $components['DA'] => null, $components['HRA'] => null,
                    $components['CONV'] => 1600.0, $components['PF'] => null, $components['PT'] => 200.0,
                ]);
            }

            // September: calculated, approved by the principal, paid.
            $september = $payroll->createRun('2026-09')->refresh();
            $payroll->compute($september, $this->fresh($accounts));
            $this->as($principal, fn () => $payroll->approve($september->refresh(), $this->fresh($principal)));
            $payroll->finalize($september->refresh(), '2026-09-30', $this->fresh($accounts));

            // October: some loss of pay and an honorarium; calculated, waiting for approval.
            $october = $payroll->createRun('2026-10')->refresh();
            $slips = Payslip::query()->where('payroll_run_id', $october->id)->orderBy('id')->get();
            $payroll->setInputs($slips[3], 2, []);
            $payroll->setInputs($slips[7], 0, [['kind' => 'earning', 'label' => 'Exam duty honorarium (IA2)', 'amount' => 1500.0]]);
            $payroll->setInputs($slips[9], 1, [['kind' => 'deduction', 'label' => 'Salary advance recovery', 'amount' => 5000.0]]);
            $payroll->compute($october->refresh(), $this->fresh($accounts));
        });
    }
}
