<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Modules\Identity\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    private function activate(string $email): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        $user->forceFill(['password' => Hash::make(UserFactory::PASSWORD), 'status' => UserStatus::Active, 'email_verified_at' => now(), 'must_change_password' => false])->save();

        return $user;
    }

    public function test_structure_demands_concession_payment_receipt_cancel_and_reports(): void
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $accounts = $this->member($institution, ['finance_officer'], ['email' => 'accounts@example.edu']);
        $principal = $this->member($institution, ['institution_head'], ['email' => 'principal@example.edu']);

        $department = $this->api('POST', 'departments', $admin, ['code' => 'MCA', 'name' => 'Computer Applications'])->json('data');
        $programme = $this->api('POST', 'programmes', $admin, ['department_id' => $department['id'], 'code' => 'MCA', 'name' => 'MCA', 'level' => 'pg', 'duration_years' => 2])->json('data');
        $batch = $this->api('POST', 'batches', $admin, ['programme_id' => $programme['id'], 'admission_year' => 2026, 'sections' => ['A']])->json('data');
        foreach (['asha', 'bala'] as $name) {
            $this->api('POST', 'students', $admin, ['name' => ucfirst($name), 'email' => "{$name}@example.edu", 'programme_id' => $programme['id'], 'admission_type' => 'cet', 'admitted_on' => '2026-08-01', 'batch_id' => $batch['id'], 'section_id' => $batch['sections'][0]['id'], 'send_invite' => false])->assertCreated();
        }
        $asha = $this->activate('asha@example.edu');

        $tuition = $this->api('POST', 'fee-heads', $accounts, ['code' => 'TUI', 'name' => 'Tuition fee'])->assertCreated()->json('data.id');
        $exam = $this->api('POST', 'fee-heads', $accounts, ['code' => 'EXM', 'name' => 'Examination fee'])->json('data.id');
        $this->api('POST', 'fee-heads', $accounts, ['code' => 'TUI', 'name' => 'Duplicate'])->assertStatus(422);
        $this->api('POST', 'fee-heads', $asha, ['code' => 'X', 'name' => 'X'])->assertForbidden();

        $structure = $this->api('POST', 'fee-structures', $accounts, [
            'name' => 'MCA semester 1 fees', 'programme_id' => $programme['id'], 'admission_year' => 2026, 'term_number' => 1, 'due_on' => now()->subDay()->toDateString(),
            'items' => [['fee_head_id' => $tuition, 'amount' => 50000], ['fee_head_id' => $exam, 'amount' => 2500]],
        ])->assertCreated()->assertJsonPath('data.total', 52500)->json('data.id');

        $this->api('POST', 'fee-structures/'.$structure.'/generate', $accounts)->assertOk()->assertJsonPath('data.raised', 2);
        $this->api('POST', 'fee-structures/'.$structure.'/generate', $accounts)->assertJsonPath('data.raised', 0);

        $ashaId = $this->api('GET', 'me/fees', $asha)->assertOk()->assertJsonPath('data.totals.balance', 52500)->assertJsonPath('data.totals.overdue', 52500)->json('data.student.id');
        $demand = $this->api('GET', 'fee-demands?filter[student]='.$ashaId, $accounts)->assertJsonPath('data.0.overdue', true)->json('data.0.id');

        // Concession: asked by accounts, decided by the principal.
        $this->api('POST', 'fee-demands/'.$demand.'/concessions', $accounts, ['amount' => 60000, 'reason' => 'x'])->assertStatus(422);
        $concession = $this->api('POST', 'fee-demands/'.$demand.'/concessions', $accounts, ['amount' => 10000, 'reason' => 'Merit scholarship'])->assertCreated()->json('data.id');
        $this->api('POST', 'fee-concessions/'.$concession.'/decide', $principal, ['approve' => true])->assertOk();

        // Payments: overpayment refused, cheque needs a reference, receipts numbered.
        $this->api('POST', 'fee-payments', $accounts, ['student_id' => $ashaId, 'amount' => 50000, 'mode' => 'cash', 'paid_on' => today()->toDateString()])->assertStatus(422)->assertJsonPath('code', 'OVERPAYMENT');
        $this->api('POST', 'fee-payments', $accounts, ['student_id' => $ashaId, 'amount' => 20000, 'mode' => 'dd', 'paid_on' => today()->toDateString()])->assertStatus(422)->assertJsonPath('code', 'REFERENCE_REQUIRED');
        $first = $this->api('POST', 'fee-payments', $accounts, ['student_id' => $ashaId, 'amount' => 20000, 'mode' => 'cash', 'paid_on' => today()->toDateString()])
            ->assertCreated()->assertJsonPath('data.balance_after', 22500)->assertJsonPath('data.paid_for.0.amount', 20000);
        $this->assertMatchesRegularExpression('#/RCPT/\d{4}/000001$#', $first->json('data.receipt_no'));
        $second = $this->api('POST', 'fee-payments', $accounts, ['student_id' => $ashaId, 'amount' => 22500, 'mode' => 'upi', 'reference' => 'UPI-998877', 'paid_on' => today()->toDateString()])->json('data');

        $this->api('GET', 'students/'.$ashaId.'/no-dues', $accounts)->assertJsonPath('data.no_dues', true);
        $this->api('GET', 'fee-payments/'.$second['id'], $asha)->assertOk()->assertJsonPath('data.mode', 'upi');

        // Cancelling a receipt reverses it; the demand is due again.
        $this->api('POST', 'fee-payments/'.$second['id'].'/cancel', $accounts, ['reason' => 'UPI payment bounced'])->assertOk();
        $this->api('GET', 'students/'.$ashaId.'/fee-account', $accounts)->assertJsonPath('data.totals.balance', 22500)->assertJsonPath('data.demands.0.status', 'partly_paid')->assertJsonPath('data.payments.0.status', 'cancelled');
        $this->api('POST', 'fee-demands/'.$demand.'/cancel', $accounts, ['reason' => 'x'])->assertStatus(409)->assertJsonPath('code', 'DEMAND_PAID');

        // One-off charge for the other student, and reports.
        $balaId = $this->api('GET', 'fee-demands?filter[search]=Bala', $accounts)->json('data.0.student.id');
        $this->api('POST', 'fee-demands', $accounts, ['student_id' => $balaId, 'title' => 'Library fine', 'due_on' => today()->addWeek()->toDateString(), 'items' => [['fee_head_id' => $exam, 'amount' => 150]]])->assertCreated();
        $this->api('GET', 'finance/reports/collections', $accounts)->assertOk()->assertJsonPath('data.total', 20000)->assertJsonPath('data.by_mode.cash', 20000);
        $this->api('GET', 'finance/reports/dues', $principal)->assertOk()->assertJsonPath('data.students', 2)->assertJsonPath('data.rows.0.balance', 52650);
        $this->api('GET', 'finance/reports/dues', $asha)->assertForbidden();
    }
}
