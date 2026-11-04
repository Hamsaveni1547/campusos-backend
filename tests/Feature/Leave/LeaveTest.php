<?php

namespace Tests\Feature\Leave;

use App\Models\User;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Institution\Models\CalendarEvent;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LeaveTest extends TestCase
{
    use RefreshDatabase;

    public function test_balances_apply_with_working_days_approval_cancel_and_unpaid_leave_reaches_payroll(): void
    {
        $this->travelTo('2026-11-02 09:00'); // a Monday
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $hr = $this->member($institution, ['hr_officer'], ['email' => 'hr@example.edu']);
        $principal = $this->member($institution, ['institution_head'], ['email' => 'principal@example.edu']);

        $kavya = $this->api('POST', 'employees', $admin, ['name' => 'Dr. Kavya', 'email' => 'kavya@example.edu', 'staff_type' => 'teaching', 'employment_type' => 'permanent', 'joined_on' => '2020-06-01', 'send_invite' => false])->json('data.id');
        $user = User::query()->where('email', 'kavya@example.edu')->firstOrFail();
        $user->forceFill(['password' => Hash::make(UserFactory::PASSWORD), 'status' => UserStatus::Active, 'email_verified_at' => now(), 'must_change_password' => false])->save();

        $casual = $this->api('POST', 'leave-types', $hr, ['code' => 'cl', 'name' => 'Casual leave', 'days_per_year' => 12, 'is_paid' => true, 'allow_half_day' => true])->assertCreated()->assertJsonPath('data.code', 'CL')->json('data.id');
        $unpaid = $this->api('POST', 'leave-types', $hr, ['code' => 'LOP', 'name' => 'Leave without pay', 'days_per_year' => 0, 'is_paid' => false, 'allow_half_day' => false])->json('data.id');
        $this->api('POST', 'leave-types', $user, ['code' => 'X', 'name' => 'X', 'days_per_year' => 1, 'is_paid' => true, 'allow_half_day' => true])->assertForbidden();

        $this->api('POST', 'leave-balances/allot', $hr)->assertOk()->assertJsonPath('data.created', 1);
        $this->api('POST', 'leave-balances/allot', $hr)->assertJsonPath('data.created', 0);

        // Mon 9 – Sat 14 Nov with Deepavali on Mon 9: 5 working days.
        CalendarEvent::query()->create(['title' => 'Deepavali', 'type' => 'holiday', 'starts_on' => '2026-11-09', 'ends_on' => '2026-11-09', 'is_holiday' => true, 'audience' => 'all']);
        $week = $this->api('POST', 'me/leave-applications', $user, ['leave_type_id' => $casual, 'from_date' => '2026-11-09', 'to_date' => '2026-11-15', 'reason' => 'Family function'])->assertCreated()->assertJsonPath('data.days', 5)->json('data.id');
        $this->api('POST', 'me/leave-applications', $user, ['leave_type_id' => $casual, 'from_date' => '2026-11-12', 'to_date' => '2026-11-12', 'reason' => 'x'])->assertStatus(409)->assertJsonPath('code', 'LEAVE_OVERLAP');
        $this->api('POST', 'me/leave-applications', $user, ['leave_type_id' => $casual, 'from_date' => '2026-11-16', 'to_date' => '2026-11-28', 'reason' => 'x'])->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_BALANCE');
        $this->api('POST', 'me/leave-applications', $user, ['leave_type_id' => $unpaid, 'from_date' => '2026-11-03', 'to_date' => '2026-11-03', 'half_day' => true, 'reason' => 'x'])->assertStatus(422)->assertJsonPath('code', 'HALF_DAY_INVALID');
        $this->api('POST', 'me/leave-applications', $user, ['leave_type_id' => $casual, 'from_date' => '2026-11-08', 'to_date' => '2026-11-08', 'reason' => 'x'])->assertStatus(422)->assertJsonPath('code', 'NO_WORKING_DAYS');

        $this->api('GET', 'me/leave', $user)->assertOk()->assertJsonPath('data.balances.0.pending', 5)->assertJsonPath('data.balances.0.available', 7);

        $this->api('POST', 'leave-applications/'.$week.'/decide', $user, ['approve' => true])->assertForbidden();
        $this->api('GET', 'leave-applications?filter[status]=pending', $principal)->assertOk()->assertJsonPath('data.0.can_decide', true);
        $this->api('POST', 'leave-applications/'.$week.'/decide', $principal, ['approve' => true])->assertOk();
        $this->api('POST', 'me/leave-applications/'.$week.'/cancel', $user)->assertOk();
        $this->api('GET', 'me/leave', $user)->assertJsonPath('data.balances.0.available', 12);

        // Two days without pay in November become loss-of-pay days in payroll.
        $lop = $this->api('POST', 'me/leave-applications', $user, ['leave_type_id' => $unpaid, 'from_date' => '2026-11-19', 'to_date' => '2026-11-20', 'reason' => 'Personal'])->assertCreated()->json('data.id');
        $this->api('POST', 'leave-applications/'.$lop.'/decide', $principal, ['approve' => false])->assertStatus(422);
        $this->api('POST', 'leave-applications/'.$lop.'/decide', $principal, ['approve' => true])->assertOk();

        $basic = $this->api('POST', 'pay-components', $admin, ['code' => 'BASIC', 'name' => 'Basic', 'kind' => 'earning', 'calculation' => 'fixed'])->json('data.id');
        $this->api('PUT', 'employees/'.$kavya.'/salary', $admin, ['components' => [['id' => $basic, 'amount' => 30000]]])->assertOk();
        $run = $this->api('POST', 'payroll-runs', $admin, ['month' => '2026-11'])->json('data.id');
        $this->api('GET', 'payroll-runs/'.$run, $admin)->assertJsonPath('data.payslips.0.lop_days', 2);
    }
}
