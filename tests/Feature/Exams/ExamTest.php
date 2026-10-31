<?php

namespace Tests\Feature\Exams;

use App\Models\User;
use App\Modules\Academics\Models\Course;
use App\Modules\Exams\Models\ExamCycle;
use App\Modules\Exams\Models\ExamRegistration;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Students\Models\Student;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ExamTest extends TestCase
{
    use RefreshDatabase;

    private function activate(string $email): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        $user->forceFill(['password' => Hash::make(UserFactory::PASSWORD), 'status' => UserStatus::Active, 'email_verified_at' => now(), 'must_change_password' => false])->save();

        return $user;
    }

    /**
     * Section A takes DBMS (4 credits) taught by Kavya; Asha and Bala are registered.
     * Internal scheme: one 40-mark test worth 40 — Asha 33, Bala 10 — submitted but not yet verified.
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
        $this->api('POST', 'offering-sections/'.$class.'/allocations', $admin, ['employee_id' => $kavya['id'], 'component' => 'theory', 'weekly_hours' => 4]);

        $students = [];
        foreach (['asha', 'bala'] as $name) {
            $students[$name] = $this->api('POST', 'students', $admin, ['name' => ucfirst($name), 'email' => "{$name}@example.edu", 'programme_id' => $programme['id'], 'admission_type' => 'cet', 'admitted_on' => now()->subWeeks(3)->toDateString(), 'batch_id' => $batch['id'], 'section_id' => $section, 'send_invite' => false])->json('data');
            $this->api('POST', 'me/term-registration', $this->activate("{$name}@example.edu"), ['elective_offering_ids' => []])->assertOk();
        }

        $teacher = $this->activate('kavya@example.edu');
        $group = $this->api('POST', 'offerings/'.$offering['id'].'/assessment-groups', $teacher, ['name' => 'Test', 'weight' => 40])->json('data.id');
        $test = $this->api('POST', 'assessment-groups/'.$group.'/assessments', $teacher, ['name' => 'T1', 'kind' => 'test', 'max_marks' => 40])->json('data.id');
        $this->api('PUT', 'assessments/'.$test.'/marks', $teacher, ['class' => $class, 'marks' => [
            $students['asha']['id'] => ['marks' => 33, 'status' => 'present'], $students['bala']['id'] => ['marks' => 10, 'status' => 'present'],
        ]])->assertOk();
        $this->api('POST', 'classes/'.$class.'/internal-marks/submit', $teacher)->assertOk();

        return ['institution' => $institution, 'admin' => $admin, 'term' => $term['id'], 'course' => $course['id'], 'class' => $class];
    }

    /**
     * @return array<string, string> student name => script code
     */
    private function codes(User $admin, string $cycle): array
    {
        return collect($this->api('GET', 'exam-cycles/'.$cycle.'/registrations', $admin)->assertOk()->json('data'))->mapWithKeys(fn (array $r) => [$r['student']['name'] => $r['script_code']])->all();
    }

    private function runExam(User $admin, User $examiner, string $cycle, string $course, array $marks): void
    {
        $schedule = $this->api('POST', 'exam-cycles/'.$cycle.'/schedules', $admin, ['course_id' => $course, 'exam_date' => now()->addWeek()->toDateString(), 'starts_at' => '10:00', 'ends_at' => '13:00', 'max_marks' => 60])->assertCreated()->json('data.id');
        $codes = $this->codes($admin, $cycle);
        $this->api('PUT', 'exam-schedules/'.$schedule.'/marks', $admin, ['marks' => collect($marks)->mapWithKeys(fn ($m, $name) => [$codes[$name] => $m === null ? ['attendance' => 'absent'] : ['attendance' => 'present', 'marks' => $m]])->all()])->assertOk();
        $this->api('POST', 'exam-cycles/'.$cycle.'/process', $admin)->assertOk()->assertJsonPath('data.status', 'results_processed');
        $this->api('POST', 'exam-cycles/'.$cycle.'/verify', $admin)->assertStatus(409)->assertJsonPath('code', 'SAME_PERSON');
        $this->api('POST', 'exam-cycles/'.$cycle.'/verify', $examiner)->assertOk();
        $this->api('POST', 'exam-cycles/'.$cycle.'/publish', $admin)->assertOk()->assertJsonPath('data.status', 'published');
    }

    public function test_regular_cycle_from_registration_to_published_results_and_revaluation(): void
    {
        ['institution' => $institution, 'admin' => $admin, 'term' => $term, 'course' => $course, 'class' => $class] = $this->campus();
        $examiner = $this->member($institution, ['examiner'], ['email' => 'coe@example.edu']);
        $kavya = $this->activate('kavya@example.edu');
        $asha = $this->activate('asha@example.edu');
        $bala = $this->activate('bala@example.edu');

        $this->api('GET', 'exams/options', $admin)->assertOk()->assertJsonPath('data.external_pass_percent', 35)->assertJsonPath('data.overall_pass_percent', 40)->assertJsonCount(7, 'data.grades');
        $this->api('POST', 'exam-cycles', $kavya, ['name' => 'Nov 2026', 'type' => 'regular', 'term_id' => $term])->assertForbidden();
        $cycle = $this->api('POST', 'exam-cycles', $admin, ['name' => 'Nov 2026', 'type' => 'regular', 'term_id' => $term])->assertCreated()->json('data.id');

        $this->api('POST', 'exam-cycles/'.$cycle.'/open-registration', $admin)->assertOk()->assertJsonPath('data.status', 'registration_open');
        $this->api('POST', 'exam-cycles/'.$cycle.'/open-registration', $admin)->assertStatus(409);
        $this->api('POST', 'exam-cycles/'.$cycle.'/close-registration', $admin)->assertOk();

        // Internal marks are only submitted, so nobody is eligible yet.
        $this->api('GET', 'exam-cycles/'.$cycle, $admin)->assertJsonPath('data.eligibility.not_eligible', 2)->assertJsonPath('data.can.process', true);
        $this->api('GET', 'me/exams', $asha)->assertOk()->assertJsonPath('data.cycles.0.courses.0.reasons.0', 'Internal marks not verified')->assertJsonPath('data.cycles.0.hall_ticket', false);
        $this->api('GET', 'me/hall-ticket/'.$cycle, $asha)->assertNotFound();

        $sheet = $this->api('GET', 'internal-mark-sheets', $admin)->json('data.0.id');
        $this->api('POST', 'internal-mark-sheets/'.$sheet.'/verify', $admin, ['approve' => true])->assertOk();
        $this->api('POST', 'exam-cycles/'.$cycle.'/eligibility', $admin)->assertOk();
        $this->api('GET', 'exam-cycles/'.$cycle, $admin)->assertJsonPath('data.eligibility.eligible', 2);
        $this->assertNotNull($this->codes($admin, $cycle)['Asha']);
        $this->assertNull(collect($this->api('GET', 'exam-cycles/'.$cycle.'/registrations', $this->member($institution, ['hod'], ['email' => 'hod@example.edu']))->json('data'))->first()['script_code']);

        $this->api('POST', 'exam-cycles/'.$cycle.'/process', $admin)->assertStatus(422)->assertJsonPath('code', 'MARKS_MISSING');
        $this->runExam($admin, $examiner, $cycle, $course, ['Asha' => 45, 'Bala' => null]);

        // Asha: (33 + 45) / (40 + 60) = 78% → A (8 points)
        $this->api('GET', 'me/results', $asha)->assertOk()->assertJsonPath('data.cgpa', 8)->assertJsonPath('data.results.0.courses.0.grade', 'A')->assertJsonPath('data.results.0.courses.0.can_request_revaluation', true);
        $ashaRecord = Student::query()->whereHas('user', fn ($query) => $query->where('email', 'asha@example.edu'))->value('ulid');
        $this->api('GET', 'students/'.$ashaRecord.'/academic-history', $admin)->assertOk()->assertJsonPath('data.cgpa', 8)->assertJsonPath('data.results.0.courses.0.grade', 'A');
        $this->api('GET', 'students/'.$ashaRecord.'/academic-history', $bala)->assertForbidden();
        $this->api('GET', 'me/results', $bala)->assertJsonPath('data.results.0.courses.0.grade', 'AB')->assertJsonPath('data.backlogs', 1)->assertJsonPath('data.cgpa', 0);
        $this->api('GET', 'exam-cycles/'.$cycle.'/results', $admin)->assertOk()->assertJsonPath('data.summary.pass_percent', 50)->assertJsonPath('data.summary.all_clear', 1);

        $balaRow = $this->api('GET', 'me/results', $bala)->json('data.results.0.courses.0.id');
        $this->api('POST', 'me/revaluation-requests', $bala, ['registration_id' => $balaRow, 'reason' => 'x'])->assertStatus(409)->assertJsonPath('code', 'NOT_REVALUABLE');
        $ashaRow = $this->api('GET', 'me/results', $asha)->json('data.results.0.courses.0.id');
        $this->api('POST', 'me/revaluation-requests', $asha, ['registration_id' => $ashaRow, 'reason' => 'Expected more'])->assertCreated();
        $this->api('POST', 'me/revaluation-requests', $asha, ['registration_id' => $ashaRow, 'reason' => 'again'])->assertStatus(409);

        $request = $this->api('GET', 'revaluation-requests?filter[status]=pending', $admin)->assertOk()->assertJsonPath('data.0.marks', 45)->json('data.0.id');
        $this->api('POST', 'revaluation-requests/'.$request.'/decide', $admin, ['new_marks' => 70])->assertStatus(422)->assertJsonPath('code', 'MARKS_OUT_OF_RANGE');
        $this->api('POST', 'revaluation-requests/'.$request.'/decide', $admin, ['new_marks' => 55])->assertOk();

        // (33 + 55) / 100 = 88% → A+ (9 points)
        $this->api('GET', 'me/results', $asha)->assertJsonPath('data.results.0.courses.0.grade', 'A+')->assertJsonPath('data.cgpa', 9)
            ->assertJsonPath('data.results.0.courses.0.revaluation.old_marks', 45)->assertJsonPath('data.results.0.courses.0.revaluation.new_marks', 55);

        // Supplementary: Bala clears the backlog with the internal marks carried over.
        $supply = $this->api('POST', 'exam-cycles', $admin, ['name' => 'Supplementary Dec 2026', 'type' => 'supplementary', 'term_id' => $term])->json('data.id');
        $this->api('POST', 'exam-cycles/'.$supply.'/open-registration', $admin)->assertOk();
        $backlog = $this->api('GET', 'me/exams', $bala)->assertJsonPath('data.cycles.0.backlogs_open.0.code', '21CS51')->json('data.cycles.0.backlogs_open.0.course_id');
        $this->api('POST', 'me/exam-cycles/'.$supply.'/backlogs', $asha, ['course_ids' => [$backlog]])->assertStatus(422)->assertJsonPath('code', 'NOT_A_BACKLOG');
        $this->api('POST', 'me/exam-cycles/'.$supply.'/backlogs', $bala, ['course_ids' => [$backlog]])->assertOk();
        $this->api('POST', 'exam-cycles/'.$supply.'/close-registration', $admin)->assertOk();
        $this->api('GET', 'me/hall-ticket/'.$supply, $bala)->assertOk()->assertJsonPath('data.exams.0.code', '21CS51');

        $this->runExam($admin, $examiner, $supply, $course, ['Bala' => 50]);

        // (10 + 50) / 100 = 60% → B+ (7 points), backlog cleared.
        $this->api('GET', 'me/results', $bala)->assertJsonPath('data.results.0.courses.0.grade', 'B+')->assertJsonPath('data.cgpa', 7)->assertJsonPath('data.backlogs', 0);
        $this->api('GET', 'me/hall-ticket/'.$supply, $asha)->assertNotFound();
    }

    public function test_override_and_timetable_clash(): void
    {
        ['institution' => $institution, 'admin' => $admin, 'term' => $term, 'course' => $course] = $this->campus();
        $cycle = $this->api('POST', 'exam-cycles', $admin, ['name' => 'Nov 2026', 'type' => 'regular', 'term_id' => $term])->json('data.id');
        $this->api('POST', 'exam-cycles/'.$cycle.'/open-registration', $admin);
        $this->api('POST', 'exam-cycles/'.$cycle.'/close-registration', $admin);

        $row = $this->api('GET', 'exam-cycles/'.$cycle.'/registrations?filter[eligibility]=not_eligible', $admin)->assertJsonCount(2, 'data')->json('data.0.id');
        $this->api('POST', 'exam-registrations/'.$row.'/override', $this->activate('kavya@example.edu'), ['reason' => 'x'])->assertForbidden();
        $this->api('POST', 'exam-registrations/'.$row.'/override', $admin, ['reason' => 'Medical, approved by principal'])->assertOk();
        $this->api('POST', 'exam-cycles/'.$cycle.'/eligibility', $admin)->assertOk();
        $this->api('GET', 'exam-cycles/'.$cycle.'/registrations?filter[eligibility]=eligible', $admin)->assertJsonCount(1, 'data')->assertJsonPath('data.0.override_reason', 'Medical, approved by principal');

        // A second course for the same students at an overlapping time clashes.
        $other = $this->api('POST', 'courses', $admin, ['department_id' => $this->api('GET', 'departments', $admin)->json('data.0.id'), 'code' => '21CS52', 'title' => 'OS', 'type' => 'theory', 'credits' => 3])->json('data.id');
        ExamRegistration::query()->create([
            'exam_cycle_id' => ExamCycle::query()->where('ulid', $cycle)->value('id'),
            'student_id' => Student::query()->value('id'),
            'course_id' => Course::query()->where('ulid', $other)->value('id'),
            'kind' => 'backlog',
        ]);

        $date = now()->addWeek()->toDateString();
        $this->api('POST', 'exam-cycles/'.$cycle.'/schedules', $admin, ['course_id' => $course, 'exam_date' => $date, 'starts_at' => '10:00', 'ends_at' => '13:00', 'max_marks' => 60])->assertCreated();
        $this->api('POST', 'exam-cycles/'.$cycle.'/schedules', $admin, ['course_id' => $other, 'exam_date' => $date, 'starts_at' => '12:00', 'ends_at' => '15:00', 'max_marks' => 60])->assertStatus(409)->assertJsonPath('code', 'SCHEDULE_CLASH');
        $this->api('POST', 'exam-cycles/'.$cycle.'/schedules', $admin, ['course_id' => $other, 'exam_date' => $date, 'starts_at' => '14:00', 'ends_at' => '17:00', 'max_marks' => 60])->assertCreated();
    }
}
