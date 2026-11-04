<?php

namespace Tests\Feature\Communication;

use App\Models\User;
use App\Modules\Communication\Models\Announcement;
use App\Modules\Communication\Notifications\FeeReminderNotification;
use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Institution\Models\Department;
use App\Modules\Institution\Models\Institution;
use App\Modules\Leave\Models\LeaveApplication;
use App\Modules\Leave\Models\LeaveType;
use App\Shared\Tenancy\CurrentInstitution;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EnhancementsE1Test extends TestCase
{
    use RefreshDatabase;

    private function activate(string $email): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        $user->forceFill(['password' => Hash::make(UserFactory::PASSWORD), 'status' => UserStatus::Active, 'email_verified_at' => now(), 'must_change_password' => false])->save();

        return $user;
    }

    /**
     * Two departments, each with one section, one student and one teacher.
     *
     * @return array<string, mixed>
     */
    private function campus(Institution $institution, User $admin): array
    {
        $out = [];
        foreach (['CSE', 'ISE'] as $code) {
            $department = $this->api('POST', 'departments', $admin, ['code' => $code, 'name' => $code])->json('data');
            $programme = $this->api('POST', 'programmes', $admin, ['department_id' => $department['id'], 'code' => 'BE-'.$code, 'name' => 'B.E. '.$code, 'level' => 'ug', 'duration_years' => 4])->json('data');
            $batch = $this->api('POST', 'batches', $admin, ['programme_id' => $programme['id'], 'admission_year' => 2026, 'sections' => ['A']])->json('data');
            $lower = strtolower($code);
            $this->api('POST', 'students', $admin, ['name' => 'Student '.$code, 'email' => "student.{$lower}@example.edu", 'programme_id' => $programme['id'], 'admission_type' => 'cet', 'admitted_on' => '2026-08-01', 'batch_id' => $batch['id'], 'section_id' => $batch['sections'][0]['id'], 'send_invite' => false])->assertCreated();
            $this->api('POST', 'employees', $admin, ['name' => 'Teacher '.$code, 'email' => "teacher.{$lower}@example.edu", 'staff_type' => 'teaching', 'employment_type' => 'permanent', 'department_id' => $department['id'], 'joined_on' => '2020-06-01', 'send_invite' => false])->assertCreated();
            $out[$code] = ['department' => $department['id'], 'department_pk' => Department::query()->where('ulid', $department['id'])->value('id'), 'section' => $batch['sections'][0]['id']];
        }

        return $out;
    }

    public function test_hod_is_limited_to_own_department(): void
    {
        Notification::fake();
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $campus = $this->campus($institution, $admin);
        $hod = $this->member($institution, [], ['email' => 'hod@example.edu']);
        $this->grantRole($hod, $institution, 'hod', $campus['CSE']['department_pk']);

        // Announcements: own department only.
        $this->api('GET', 'announcements/audiences', $hod)->assertOk()->assertJsonPath('data.limits.departments', [$campus['CSE']['department']]);
        $base = ['title' => 'Notice', 'body' => 'Text', 'category' => 'academic', 'priority' => 'normal'];
        $this->api('POST', 'announcements', $hod, [...$base, 'audience_type' => 'sections', 'audience_ids' => [$campus['ISE']['section']]])->assertForbidden()->assertJsonPath('code', 'OUT_OF_SCOPE');
        $this->api('POST', 'announcements', $hod, [...$base, 'audience_type' => 'departments', 'audience_ids' => [$campus['CSE']['department']]])->assertCreated();

        // Mentoring: other departments' sections are closed.
        $this->api('GET', 'mentoring/assignments?filter[section]='.$campus['ISE']['section'], $hod)->assertForbidden();
        $this->api('GET', 'mentoring/assignments?filter[section]='.$campus['CSE']['section'], $hod)->assertOk()->assertJsonCount(1, 'data');

        // Leave: only applications from own department.
        app(CurrentInstitution::class)->runAs($institution, function (): void {
            $type = LeaveType::query()->create(['code' => 'CL', 'name' => 'Casual leave', 'days_per_year' => 12]);
            foreach (Employee::query()->get() as $employee) {
                LeaveApplication::query()->create(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'from_date' => today()->addDay(), 'to_date' => today()->addDay(), 'days' => 1, 'reason' => 'Personal work']);
            }
        });
        $rows = $this->api('GET', 'leave-applications', $hod)->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $other = LeaveApplication::query()->withoutGlobalScopes()->whereHas('employee', fn ($q) => $q->withoutGlobalScopes()->where('department_id', '!=', $campus['CSE']['department_pk']))->firstOrFail();
        $this->api('POST', "leave-applications/{$other->ulid}/decide", $hod, ['approve' => true])->assertForbidden();
    }

    public function test_scheduled_announcement_and_fee_reminder_jobs(): void
    {
        Notification::fake();
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $campus = $this->campus($institution, $admin);

        $id = $this->api('POST', 'announcements', $admin, ['title' => 'Holiday tomorrow', 'body' => 'Campus closed.', 'category' => 'general', 'priority' => 'normal', 'audience_type' => 'students', 'publish_at' => now()->addHour()->toIso8601String()])->assertCreated()->json('data.id');
        $this->api('POST', "announcements/{$id}/publish", $admin)->assertOk()->assertJsonPath('data.status', 'scheduled');

        $this->artisan('campusos:alerts announcements')->assertSuccessful();
        $this->assertSame('scheduled', Announcement::query()->withoutGlobalScopes()->where('ulid', $id)->value('status'));
        $this->travel(2)->hours();
        $this->artisan('campusos:alerts announcements')->assertSuccessful();
        $this->assertSame('published', Announcement::query()->withoutGlobalScopes()->where('ulid', $id)->value('status'));
        $this->travelBack();

        $head = $this->api('POST', 'fee-heads', $admin, ['code' => 'TUI', 'name' => 'Tuition fee'])->json('data.id');
        $studentId = $this->api('GET', 'students?filter[search]=student.cse', $admin)->json('data.0.id');
        $this->api('POST', 'fee-demands', $admin, ['student_id' => $studentId, 'title' => 'Tuition', 'due_on' => today()->addDays(3)->toDateString(), 'items' => [['fee_head_id' => $head, 'amount' => 5000]]])->assertCreated();

        $this->artisan('campusos:alerts fees')->assertSuccessful();
        Notification::assertSentTo(User::query()->where('email', 'student.cse@example.edu')->firstOrFail(), FeeReminderNotification::class);
        $this->artisan('campusos:alerts attendance')->assertSuccessful();
        $this->artisan('campusos:alerts nonsense')->assertFailed();
        $this->assertArrayHasKey('CSE', $campus);
    }

    public function test_simulated_online_payment(): void
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $this->campus($institution, $admin);
        $student = $this->activate('student.cse@example.edu');

        $this->api('POST', 'me/online-payments', $student)->assertUnprocessable()->assertJsonPath('code', 'NOTHING_DUE');

        $head = $this->api('POST', 'fee-heads', $admin, ['code' => 'TUI', 'name' => 'Tuition fee'])->json('data.id');
        $studentId = $this->api('GET', 'students?filter[search]=student.cse', $admin)->json('data.0.id');
        $this->api('POST', 'fee-demands', $admin, ['student_id' => $studentId, 'title' => 'Tuition', 'due_on' => today()->addWeek()->toDateString(), 'items' => [['fee_head_id' => $head, 'amount' => 5000]]])->assertCreated();

        $first = $this->api('POST', 'me/online-payments', $student)->assertCreated()->assertJsonPath('data.amount', 5000)->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->api('POST', "me/online-payments/{$first}/complete", $student, ['method' => 'card', 'outcome' => 'failure'])->assertOk()->assertJsonPath('data.status', 'failed');
        $this->api('POST', "me/online-payments/{$first}/complete", $student, ['method' => 'card', 'outcome' => 'success'])->assertUnprocessable()->assertJsonPath('code', 'ALREADY_COMPLETED');

        $this->api('POST', 'me/online-payments', $student, ['amount' => 6000])->assertUnprocessable()->assertJsonPath('code', 'OVERPAYMENT');
        $second = $this->api('POST', 'me/online-payments', $student, ['amount' => 2000])->json('data.id');
        $this->api('POST', "me/online-payments/{$second}/complete", $student, ['method' => 'upi', 'outcome' => 'success'])->assertOk()
            ->assertJsonPath('data.status', 'success')->assertJsonPath('data.receipt.receipt_no', fn ($no) => is_string($no) && $no !== '');

        $this->api('GET', 'me/fees', $student)->assertOk()->assertJsonPath('data.totals.balance', 3000);
        $this->api('GET', 'me/online-payments', $student)->assertOk()->assertJsonCount(2, 'data');
        $this->api('POST', "me/online-payments/{$second}/complete", $admin, ['method' => 'upi', 'outcome' => 'success'])->assertNotFound();
    }
}
