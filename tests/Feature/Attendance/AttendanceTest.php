<?php

namespace Tests\Feature\Attendance;

use App\Models\User;
use App\Modules\Identity\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private function activate(string $email): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        $user->forceFill(['password' => Hash::make(UserFactory::PASSWORD), 'status' => UserStatus::Active, 'email_verified_at' => now(), 'must_change_password' => false])->save();

        return $user;
    }

    /**
     * Section A takes DBMS (Kavya teaches) at 00:00 every day of the week; Asha and Bala are registered.
     *
     * @return array<string, mixed>
     */
    private function campus(): array
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);

        $department = $this->api('POST', 'departments', $admin, ['code' => 'CSE', 'name' => 'Computer Science'])->json('data');
        $programme = $this->api('POST', 'programmes', $admin, ['department_id' => $department['id'], 'code' => 'BE-CSE', 'name' => 'B.E. CSE', 'level' => 'ug', 'duration_years' => 4])->json('data');
        $batch = $this->api('POST', 'batches', $admin, ['programme_id' => $programme['id'], 'admission_year' => 2026, 'sections' => ['A']])->json('data');
        $section = $batch['sections'][0]['id'];
        $year = $this->api('POST', 'academic-years', $admin, ['name' => '2026-27', 'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonths(11)->toDateString()])->json('data');
        $term = $this->api('POST', 'terms', $admin, ['academic_year_id' => $year['id'], 'type' => 'odd', 'name' => 'Odd 2026', 'starts_on' => now()->subWeeks(3)->toDateString(), 'ends_on' => now()->addMonths(4)->toDateString()])->json('data');
        $this->api('POST', 'terms/'.$term['id'].'/make-current', $admin);

        $course = $this->api('POST', 'courses', $admin, ['department_id' => $department['id'], 'code' => '21CS51', 'title' => 'DBMS', 'type' => 'theory', 'credits' => 4])->json('data');
        $offering = $this->api('POST', 'course-offerings', $admin, ['course_id' => $course['id'], 'term_id' => $term['id'], 'section_ids' => [$section]])->json('data');
        $class = $offering['sections'][0]['id'];

        $kavya = $this->api('POST', 'employees', $admin, ['name' => 'Dr. Kavya', 'email' => 'kavya@example.edu', 'staff_type' => 'teaching', 'employment_type' => 'permanent', 'joined_on' => '2020-06-01', 'send_invite' => false])->json('data');
        $this->api('POST', 'employees', $admin, ['name' => 'Dr. Ravi', 'email' => 'ravi@example.edu', 'staff_type' => 'teaching', 'employment_type' => 'permanent', 'joined_on' => '2020-06-01', 'send_invite' => false]);
        $this->api('POST', 'offering-sections/'.$class.'/allocations', $admin, ['employee_id' => $kavya['id'], 'component' => 'theory', 'weekly_hours' => 7]);

        $template = $this->api('POST', 'slot-templates', $admin, ['name' => 'Midnight', 'working_days' => [1, 2, 3, 4, 5, 6, 7], 'slots' => [['label' => 'P1', 'starts_at' => '00:00', 'ends_at' => '00:01']]])->json('data');
        $slot = $template['slots'][0]['id'];
        $timetable = $this->api('POST', 'timetables', $admin, ['term_id' => $term['id'], 'section_id' => $section, 'slot_template_id' => $template['id']])->json('data');
        foreach (range(1, 7) as $day) {
            $this->api('POST', 'timetables/'.$timetable['id'].'/entries', $admin, ['day_of_week' => $day, 'time_slot_id' => $slot, 'offering_section_id' => $class])->assertOk();
        }
        $this->api('POST', 'timetables/'.$timetable['id'].'/publish', $admin)->assertOk();

        $students = [];
        foreach (['asha', 'bala'] as $name) {
            $students[$name] = $this->api('POST', 'students', $admin, ['name' => ucfirst($name), 'email' => "{$name}@example.edu", 'programme_id' => $programme['id'], 'admission_type' => 'cet', 'admitted_on' => now()->subWeeks(3)->toDateString(), 'batch_id' => $batch['id'], 'section_id' => $section, 'send_invite' => false])->json('data');
            $this->api('POST', 'me/term-registration', $this->activate("{$name}@example.edu"), ['elective_offering_ids' => []])->assertOk();
        }

        return compact('institution', 'admin', 'section', 'class', 'slot', 'students');
    }

    public function test_teacher_marks_attendance_and_percentages_drive_shortage_and_condonation(): void
    {
        ['admin' => $admin, 'section' => $section, 'class' => $class, 'slot' => $slot, 'students' => $students] = $this->campus();
        $kavya = $this->activate('kavya@example.edu');
        $ravi = $this->activate('ravi@example.edu');
        $day = fn (int $ago): string => now()->subDays($ago)->toDateString();

        $this->api('GET', 'attendance/options', $kavya)->assertOk()->assertJsonPath('data.threshold', 75)->assertJsonPath('data.counts_as_attended', ['present', 'late', 'on_duty']);
        $this->assertCount(7, $this->api('GET', 'me/attendance/pending', $kavya)->assertOk()->json('data'));

        $this->api('GET', 'attendance/sheet?class='.$class.'&date='.$day(1).'&slot='.$slot, $kavya)->assertOk()
            ->assertJsonPath('data.can_mark', true)->assertJsonCount(2, 'data.students')->assertJsonPath('data.students.0.status', null);

        $this->api('POST', 'attendance/sheet', $ravi, ['class_id' => $class, 'date' => $day(1), 'time_slot_id' => $slot, 'statuses' => []])->assertForbidden()->assertJsonPath('code', 'NOT_CLASS_TEACHER');
        $this->api('POST', 'attendance/sheet', $kavya, ['class_id' => $class, 'date' => now()->addDay()->toDateString(), 'time_slot_id' => $slot, 'statuses' => []])->assertStatus(422)->assertJsonPath('code', 'DATE_INVALID');

        foreach (range(1, 7) as $ago) {
            $statuses = $ago <= 2 ? [$students['bala']['id'] => 'absent'] : [];
            $this->api('POST', 'attendance/sheet', $kavya, ['class_id' => $class, 'date' => $day($ago), 'time_slot_id' => $slot, 'statuses' => $statuses])->assertOk();
        }

        $this->assertSame([now()->toDateString()], array_column($this->api('GET', 'me/attendance/pending', $kavya)->json('data'), 'date'));
        $summary = collect($this->api('GET', 'attendance/classes/'.$class.'/summary', $kavya)->assertOk()->json('data.students'))->keyBy('name');
        $this->assertSame(100.0, (float) $summary['Asha']['percentage']);
        $this->assertSame(71.43, (float) $summary['Bala']['percentage']);
        $this->assertSame('condonable', $summary['Bala']['standing']);

        $this->api('GET', 'attendance/shortage?filter[section]='.$section, $admin)->assertOk()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.name', 'Bala');
        $this->api('POST', 'attendance/condonations', $admin, ['student_id' => $students['asha']['id'], 'class_id' => $class, 'reason' => 'x'])->assertStatus(422)->assertJsonPath('code', 'NOT_CONDONABLE');
        $this->api('POST', 'attendance/condonations', $kavya, ['student_id' => $students['bala']['id'], 'class_id' => $class, 'reason' => 'x'])->assertForbidden();
        $this->api('POST', 'attendance/condonations', $admin, ['student_id' => $students['bala']['id'], 'class_id' => $class, 'reason' => 'Hospitalised', 'fee_reference' => 'CND-1'])->assertCreated();
        $this->api('GET', 'attendance/shortage?filter[section]='.$section, $admin)->assertJsonCount(0, 'data.rows');

        $bala = $this->activate('bala@example.edu');
        $this->api('GET', 'me/attendance', $bala)->assertOk()->assertJsonPath('data.courses.0.standing', 'condoned')->assertJsonPath('data.courses.0.held', 7);
    }

    public function test_edit_window_corrections_and_staff_override(): void
    {
        ['institution' => $institution, 'admin' => $admin, 'class' => $class, 'slot' => $slot, 'students' => $students] = $this->campus();
        $kavya = $this->activate('kavya@example.edu');
        $bala = $this->activate('bala@example.edu');
        $old = now()->subDays(5)->toDateString();

        $this->api('POST', 'attendance/sheet', $kavya, ['class_id' => $class, 'date' => $old, 'time_slot_id' => $slot, 'statuses' => [$students['bala']['id'] => 'absent']])->assertOk();
        $this->api('POST', 'attendance/sheet', $kavya, ['class_id' => $class, 'date' => $old, 'time_slot_id' => $slot, 'statuses' => []])->assertStatus(409)->assertJsonPath('code', 'EDIT_WINDOW_CLOSED');
        $this->api('GET', 'attendance/sheet?class='.$class.'&date='.$old.'&slot='.$slot, $kavya)->assertJsonPath('data.can_mark', false)->assertJsonPath('data.students.1.status', 'absent');

        $session = $this->api('GET', 'me/attendance/'.$class, $bala)->assertOk()->assertJsonPath('data.records.0.status', 'absent')->json('data.records.0.session_id');
        $this->api('POST', 'attendance-corrections', $bala, ['session_id' => $session, 'requested_status' => 'absent', 'reason' => 'x'])->assertStatus(422)->assertJsonPath('code', 'NO_CHANGE');
        $this->api('POST', 'attendance-corrections', $bala, ['session_id' => $session, 'requested_status' => 'on_duty', 'reason' => 'Represented college at hackathon'])->assertCreated();
        $this->api('POST', 'attendance-corrections', $bala, ['session_id' => $session, 'requested_status' => 'on_duty', 'reason' => 'again'])->assertStatus(409)->assertJsonPath('code', 'CORRECTION_PENDING');

        $correction = $this->api('GET', 'attendance-corrections', $admin)->assertOk()->assertJsonPath('data.0.student.name', 'Bala')->assertJsonPath('data.0.current_status', 'absent')->json('data.0.id');
        $this->api('POST', 'attendance-corrections/'.$correction.'/decide', $kavya, ['approve' => true])->assertForbidden();
        $this->api('POST', 'attendance-corrections/'.$correction.'/decide', $admin, ['approve' => true])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->api('GET', 'me/attendance/'.$class, $bala)->assertJsonPath('data.records.0.status', 'on_duty')->assertJsonPath('data.records.0.correction.status', 'approved');

        // Admin staff hold attendance.records.edit and can change closed sessions.
        $office = $this->member($institution, ['admin_staff'], ['email' => 'office@example.edu']);
        $this->api('POST', 'attendance/sheet', $office, ['class_id' => $class, 'date' => $old, 'time_slot_id' => $slot, 'statuses' => [$students['asha']['id'] => 'medical']])->assertOk();
        $this->api('GET', 'me/attendance', $bala)->assertJsonPath('data.courses.0.percentage', 100);

        $this->api('POST', 'attendance/sheet', $kavya, ['class_id' => $class, 'date' => now()->toDateString(), 'statuses' => ['01ZZZZZZZZZZZZZZZZZZZZZZZZ' => 'absent']])->assertStatus(422)->assertJsonPath('code', 'STUDENT_NOT_IN_CLASS');
        $this->api('GET', 'me/attendance', $admin)->assertNotFound()->assertJsonPath('code', 'NOT_A_STUDENT');
    }
}
