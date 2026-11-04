<?php

namespace Tests\Feature\Mentoring;

use App\Models\User;
use App\Modules\Identity\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MentoringTest extends TestCase
{
    use RefreshDatabase;

    private function activate(string $email): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        $user->forceFill(['password' => Hash::make(UserFactory::PASSWORD), 'status' => UserStatus::Active, 'email_verified_at' => now(), 'must_change_password' => false])->save();

        return $user;
    }

    public function test_allocation_mentee_view_notes_concerns_meetings_and_student_side(): void
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $principal = $this->member($institution, ['institution_head'], ['email' => 'principal@example.edu']);

        $department = $this->api('POST', 'departments', $admin, ['code' => 'CSE', 'name' => 'CSE'])->json('data');
        $programme = $this->api('POST', 'programmes', $admin, ['department_id' => $department['id'], 'code' => 'BE-CSE', 'name' => 'B.E. CSE', 'level' => 'ug', 'duration_years' => 4])->json('data');
        $batch = $this->api('POST', 'batches', $admin, ['programme_id' => $programme['id'], 'admission_year' => 2026, 'sections' => ['A']])->json('data');
        $section = $batch['sections'][0]['id'];

        $mentors = [];
        foreach (['kavya', 'ravi'] as $name) {
            $mentors[$name] = $this->api('POST', 'employees', $admin, ['name' => 'Dr. '.ucfirst($name), 'email' => "{$name}@example.edu", 'staff_type' => 'teaching', 'employment_type' => 'permanent', 'joined_on' => '2020-06-01', 'send_invite' => false])->json('data.id');
        }
        foreach (['asha', 'bala', 'chetan'] as $i => $name) {
            $this->api('POST', 'students', $admin, ['name' => ucfirst($name), 'email' => "{$name}@example.edu", 'programme_id' => $programme['id'], 'admission_type' => 'cet', 'admitted_on' => '2026-08-01', 'batch_id' => $batch['id'], 'section_id' => $section, 'roll_no' => '0'.($i + 1), 'send_invite' => false]);
        }
        $kavya = $this->activate('kavya@example.edu');
        $ravi = $this->activate('ravi@example.edu');
        $asha = $this->activate('asha@example.edu');

        // Round-robin by roll number: Asha and Chetan to Kavya, Bala to Ravi.
        $this->api('POST', 'mentoring/allocate', $kavya, ['section_ids' => [$section], 'mentor_ids' => [$mentors['kavya']]])->assertForbidden();
        $this->api('POST', 'mentoring/allocate', $admin, ['section_ids' => [$section], 'mentor_ids' => [$mentors['kavya'], $mentors['ravi']]])->assertOk()->assertJsonPath('data.assigned', 3);
        $this->api('POST', 'mentoring/allocate', $admin, ['section_ids' => [$section], 'mentor_ids' => [$mentors['kavya']]])->assertJsonPath('data.assigned', 0);
        $rows = collect($this->api('GET', 'mentoring/assignments?filter[section]='.$section, $admin)->assertOk()->json('data'))->keyBy('name');
        $this->assertSame(['Dr. Kavya', 'Dr. Ravi', 'Dr. Kavya'], [$rows['Asha']['mentor']['name'], $rows['Bala']['mentor']['name'], $rows['Chetan']['mentor']['name']]);

        $mentees = $this->api('GET', 'me/mentees', $kavya)->assertOk()->assertJsonCount(2, 'data')->json('data');
        $ashaId = $mentees[0]['id'];
        $balaId = $rows['Bala']['id'];
        $this->api('GET', 'mentees/'.$balaId, $kavya)->assertForbidden();

        // Notes: confidential ones are visible to the author and counsellors only.
        $this->api('POST', 'mentees/'.$ashaId.'/notes', $kavya, ['body' => 'Settling in well; interested in robotics club.', 'visibility' => 'shared'])->assertCreated();
        $this->api('POST', 'mentees/'.$ashaId.'/notes', $kavya, ['body' => 'Family health issue at home.', 'visibility' => 'confidential'])->assertCreated();
        $concern = $this->api('POST', 'mentees/'.$ashaId.'/concerns', $kavya, ['category' => 'academic', 'description' => 'Struggling with mathematics', 'action_plan' => 'Peer tutoring twice a week', 'review_on' => now()->addWeeks(2)->toDateString()])->assertCreated()->json('data.id');

        $view = $this->api('GET', 'mentees/'.$ashaId, $kavya)->assertOk()->assertJsonCount(2, 'data.notes')->assertJsonPath('data.mentor.name', 'Dr. Kavya');
        $this->assertContains('1 open concern(s)', $view->json('data.flags'));
        $this->api('GET', 'mentees/'.$ashaId, $principal)->assertOk()->assertJsonCount(2, 'data.notes');
        $this->api('GET', 'mentees/'.$ashaId, $admin)->assertJsonCount(2, 'data.notes');
        $hod = $this->member($institution, ['hod'], ['email' => 'hod@example.edu']);
        $this->api('GET', 'mentees/'.$ashaId, $hod)->assertOk()->assertJsonCount(1, 'data.notes')->assertJsonPath('data.notes.0.visibility', 'shared');
        $this->api('GET', 'mentoring/at-risk', $principal)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.mentor', 'Dr. Kavya');

        $this->api('PATCH', 'student-concerns/'.$concern, $kavya, ['resolve' => true])->assertStatus(422);
        $this->api('PATCH', 'student-concerns/'.$concern, $kavya, ['resolve' => true, 'outcome' => 'Scored 32/40 in IA2'])->assertOk()->assertJsonPath('data.status', 'resolved');

        // The student asks for a meeting; the mentor schedules it and records minutes.
        $this->api('GET', 'me/mentor', $asha)->assertOk()->assertJsonPath('data.mentor.name', 'Dr. Kavya');
        $this->api('POST', 'me/mentor/meeting-requests', $asha, ['agenda' => 'Guidance on choosing electives'])->assertCreated();
        $this->api('POST', 'me/mentor/meeting-requests', $asha, ['agenda' => 'Again'])->assertStatus(409)->assertJsonPath('code', 'REQUEST_PENDING');
        $request = $this->api('GET', 'mentoring-meetings', $kavya)->assertJsonPath('data.0.status', 'requested')->json('data.0.id');
        $this->api('PATCH', 'mentoring-meetings/'.$request, $ravi, ['status' => 'cancelled'])->assertForbidden();
        $this->api('PATCH', 'mentoring-meetings/'.$request, $kavya, ['status' => 'scheduled', 'scheduled_at' => now()->addDays(2)->setTime(15, 0)->toDateTimeString()])->assertOk();
        $this->api('PATCH', 'mentoring-meetings/'.$request, $kavya, ['status' => 'held', 'minutes' => 'Discussed AI electives.', 'attended_ids' => [$ashaId]])->assertOk()->assertJsonPath('data.students.0.attended', true);
        $this->api('POST', 'mentoring-meetings', $kavya, ['student_ids' => [$balaId], 'scheduled_at' => now()->addWeek()->toDateTimeString(), 'agenda' => 'x'])->assertStatus(422)->assertJsonPath('code', 'NOT_MENTEE');
        $this->api('GET', 'me/mentor', $asha)->assertJsonPath('data.meetings.0.status', 'held')->assertJsonPath('data.meetings.0.minutes', null);

        // Reassignment keeps history and moves access.
        $this->api('POST', 'students/'.$ashaId.'/mentor', $admin, ['mentor_id' => $mentors['ravi'], 'reason' => 'Workload balance'])->assertOk();
        $this->api('GET', 'mentees/'.$ashaId, $kavya)->assertForbidden();
        $this->api('GET', 'mentees/'.$ashaId, $ravi)->assertOk()->assertJsonPath('data.mentor.name', 'Dr. Ravi');
    }
}
