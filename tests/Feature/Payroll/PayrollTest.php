<?php

namespace Tests\Feature\Payroll;

use App\Models\User;
use App\Modules\Identity\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PayrollTest extends TestCase
{
    use RefreshDatabase;

    public function test_salary_monthly_run_with_lop_and_adjustments_approval_and_payslip(): void
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $accounts = $this->member($institution, ['finance_officer'], ['email' => 'accounts@example.edu']);
        $principal = $this->member($institution, ['institution_head'], ['email' => 'principal@example.edu']);

        $kavya = $this->api('POST', 'employees', $admin, ['name' => 'Dr. Kavya', 'email' => 'kavya@example.edu', 'staff_type' => 'teaching', 'employment_type' => 'permanent', 'joined_on' => '2020-06-01', 'send_invite' => false])->json('data.id');
        $user = User::query()->where('email', 'kavya@example.edu')->firstOrFail();
        $user->forceFill(['password' => Hash::make(UserFactory::PASSWORD), 'status' => UserStatus::Active, 'email_verified_at' => now(), 'must_change_password' => false])->save();

        $component = fn (array $data) => $this->api('POST', 'pay-components', $accounts, $data)->assertCreated()->json('data.id');
        $basic = $component(['code' => 'BASIC', 'name' => 'Basic pay', 'kind' => 'earning', 'calculation' => 'fixed', 'affected_by_lop' => true, 'position' => 1]);
        $da = $component(['code' => 'DA', 'name' => 'Dearness allowance', 'kind' => 'earning', 'calculation' => 'percent', 'percent' => 50, 'affected_by_lop' => true, 'position' => 2]);
        $pf = $component(['code' => 'PF', 'name' => 'Provident fund', 'kind' => 'deduction', 'calculation' => 'percent', 'percent' => 12, 'max_amount' => 1800, 'affected_by_lop' => false, 'position' => 10]);
        $pt = $component(['code' => 'PT', 'name' => 'Professional tax', 'kind' => 'deduction', 'calculation' => 'fixed', 'affected_by_lop' => false, 'position' => 11]);
        $this->api('POST', 'pay-components', $accounts, ['code' => 'BASIC', 'name' => 'x', 'kind' => 'earning', 'calculation' => 'fixed'])->assertStatus(422);
        $this->api('POST', 'pay-components', $user, ['code' => 'X', 'name' => 'x', 'kind' => 'earning', 'calculation' => 'fixed'])->assertForbidden();

        $this->api('PUT', 'employees/'.$kavya.'/salary', $accounts, ['components' => [['id' => $da]]])->assertStatus(422)->assertJsonPath('code', 'BASIC_REQUIRED');
        $this->api('PUT', 'employees/'.$kavya.'/salary', $accounts, ['components' => [['id' => $basic, 'amount' => 60000], ['id' => $da], ['id' => $pf], ['id' => $pt, 'amount' => 200]]])
            ->assertOk()->assertJsonPath('data.preview.gross', 90000)->assertJsonPath('data.preview.net', 88000);
        $this->api('GET', 'payroll/salaries', $accounts)->assertJsonPath('data.0.net', 88000);

        // September: 30 days, 3 days loss of pay, exam honorarium.
        $run = $this->api('POST', 'payroll-runs', $accounts, ['month' => '2026-09'])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
        $this->api('POST', 'payroll-runs', $accounts, ['month' => '2026-09'])->assertStatus(409);
        $payslip = $this->api('GET', 'payroll-runs/'.$run, $accounts)->assertJsonCount(1, 'data.payslips')->json('data.payslips.0.id');
        $this->api('PUT', 'payslips/'.$payslip.'/inputs', $accounts, ['lop_days' => 3, 'adjustments' => [['kind' => 'earning', 'label' => 'Exam duty honorarium', 'amount' => 2000]]])->assertOk();

        $this->api('POST', 'payroll-runs/'.$run.'/compute', $accounts)->assertOk()->assertJsonPath('data.status', 'computed');
        // 27/30 → basic 54,000 + DA 27,000 + 2,000 = 83,000; PF 1,800 + PT 200
        $this->api('GET', 'payroll-runs/'.$run, $accounts)->assertJsonPath('data.totals.gross', 83000)->assertJsonPath('data.totals.net', 81000);

        $this->api('POST', 'payroll-runs/'.$run.'/approve', $accounts)->assertStatus(409)->assertJsonPath('code', 'SAME_PERSON');
        $this->api('GET', 'payslips/'.$payslip, $user)->assertForbidden();
        $this->api('POST', 'payroll-runs/'.$run.'/approve', $principal)->assertOk();
        $this->api('PUT', 'payslips/'.$payslip.'/inputs', $accounts, ['lop_days' => 0])->assertStatus(409)->assertJsonPath('code', 'RUN_LOCKED');
        $this->api('POST', 'payroll-runs/'.$run.'/finalize', $accounts, ['paid_on' => '2026-09-30'])->assertOk()->assertJsonPath('data.status', 'finalized');
        $this->api('DELETE', 'payroll-runs/'.$run, $accounts)->assertStatus(409);

        $this->api('GET', 'me/payslips', $user)->assertOk()->assertJsonPath('data.0.month', '2026-09')->assertJsonPath('data.0.net', 81000);
        $this->api('GET', 'payslips/'.$payslip, $user)->assertOk()->assertJsonPath('data.lop_days', 3)->assertJsonPath('data.lines.4.name', 'Exam duty honorarium');
    }
}
