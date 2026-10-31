<?php

namespace Tests\Feature\Assessment;

use App\Models\User;
use App\Modules\Identity\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AssessmentTest extends TestCase
{
    use RefreshDatabase;

    private function activate(string $email): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        $user->forceFill(['password' => Hash::make(UserFactory::PASSWORD), 'status' => UserStatus::Active, 'email_verified_at' => now(), 'must_change_password' => false])->save();

        return $user;
    }

    /**
     * Section A takes DBMS taught by Kavya; Asha and Bala are registered. Ravi teaches nothing.
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
        $this->api('POST', 'offering-sections/'.$class.'/allocations', $admin, ['employee_id' => $kavya['id'], 'component' => 'theory', 'weekly_hours' => 4]);

        $students = [];
        foreach (['asha', 'bala'] as $name) {
            $students[$name] = $this->api('POST', 'students', $admin, ['name' => ucfirst($name), 'email' => "{$name}@example.edu", 'programme_id' => $programme['id'], 'admission_type' => 'cet', 'admitted_on' => now()->subWeeks(3)->toDateString(), 'batch_id' => $batch['id'], 'section_id' => $section, 'send_invite' => false])->json('data');
            $this->api('POST', 'me/term-registration', $this->activate("{$name}@example.edu"), ['elective_offering_ids' => []])->assertOk();
        }

        return ['institution' => $institution, 'admin' => $admin, 'course' => $course['id'], 'offering' => $offering['id'], 'class' => $class, 'students' => $students];
    }

    public function test_scheme_marks_internal_computation_and_approval_flow(): void
    {
        ['admin' => $admin, 'course' => $course, 'offering' => $offering, 'class' => $class, 'students' => $students] = $this->campus();
        $kavya = $this->activate('kavya@example.edu');
        $ravi = $this->activate('ravi@example.edu');
        [$asha, $bala] = [$students['asha']['id'], $students['bala']['id']];

        $this->api('GET', 'assessment/options', $kavya)->assertOk()->assertJsonPath('data.round_internal', true)->assertJsonPath('data.co_target_percent', 60);
        $this->api('POST', 'courses/'.$course.'/outcomes', $kavya, ['code' => 'CO1', 'description' => 'x'])->assertForbidden();
        $this->api('POST', 'courses/'.$course.'/outcomes', $admin, ['code' => 'CO1', 'description' => 'Design ER models', 'bloom_level' => 'apply'])->assertCreated();
        $this->api('POST', 'courses/'.$course.'/outcomes', $admin, ['code' => 'CO1', 'description' => 'dup'])->assertStatus(422);

        $this->api('POST', 'offerings/'.$offering.'/assessment-groups', $ravi, ['name' => 'Tests', 'weight' => 30])->assertForbidden();
        $tests = $this->api('POST', 'offerings/'.$offering.'/assessment-groups', $kavya, ['name' => 'IA tests', 'best_of' => 2, 'weight' => 30])->assertCreated()->json('data.id');
        $work = $this->api('POST', 'offerings/'.$offering.'/assessment-groups', $kavya, ['name' => 'Assignment', 'weight' => 10])->assertCreated()->json('data.id');

        $ia = [];
        foreach (['IA1', 'IA2', 'IA3'] as $name) {
            $ia[] = $this->api('POST', 'assessment-groups/'.$tests.'/assessments', $kavya, ['name' => $name, 'kind' => 'test', 'max_marks' => 50, 'outcome_codes' => ['CO1']])->assertCreated()->json('data.id');
        }
        $a1 = $this->api('POST', 'assessment-groups/'.$work.'/assessments', $kavya, ['name' => 'A1', 'kind' => 'assignment', 'max_marks' => 10])->json('data.id');
        $this->api('POST', 'assessment-groups/'.$work.'/assessments', $kavya, ['name' => 'X', 'kind' => 'test', 'max_marks' => 10, 'outcome_codes' => ['CO9']])->assertStatus(422)->assertJsonPath('code', 'OUTCOME_INVALID');
        $this->api('GET', 'offerings/'.$offering.'/scheme', $kavya)->assertOk()->assertJsonPath('data.total_weight', 40)->assertJsonCount(3, 'data.groups.0.assessments');

        $this->api('GET', 'assessments/'.$ia[0].'/marks?class='.$class, $kavya)->assertOk()->assertJsonPath('data.editable', true)->assertJsonCount(2, 'data.students');
        $this->api('PUT', 'assessments/'.$ia[0].'/marks', $ravi, ['class' => $class, 'marks' => [$asha => ['marks' => 10, 'status' => 'present']]])->assertForbidden();
        $this->api('PUT', 'assessments/'.$ia[0].'/marks', $kavya, ['class' => $class, 'marks' => [$asha => ['marks' => 60, 'status' => 'present']]])->assertStatus(422)->assertJsonPath('code', 'MARKS_OUT_OF_RANGE');

        $save = fn (string $id, array $marks) => $this->api('PUT', 'assessments/'.$id.'/marks', $kavya, ['class' => $class, 'marks' => $marks])->assertOk();
        $save($ia[0], [$asha => ['marks' => 40, 'status' => 'present'], $bala => ['marks' => null, 'status' => 'absent']]);
        $save($ia[1], [$asha => ['marks' => 25, 'status' => 'present'], $bala => ['marks' => 30, 'status' => 'present']]);
        $save($ia[2], [$asha => ['marks' => 45, 'status' => 'present'], $bala => ['marks' => 20, 'status' => 'present']]);
        $save($a1, [$asha => ['marks' => 7.5, 'status' => 'present'], $bala => ['marks' => 5, 'status' => 'present']]);

        $sheet = $this->api('POST', 'classes/'.$class.'/internal-marks/compute', $kavya)->assertOk();
        $rows = collect($sheet->json('data.students'))->keyBy('name');
        $this->assertSame(33.0, (float) $rows['Asha']['final']);
        // Bala: best two of (0, 0.6, 0.4) = 0.5 × 30 = 15; assignment 5 → 20
        $this->assertSame(20.0, (float) $rows['Bala']['final']);
        $this->assertSame(['groups' => 2, 'total' => 40], ['groups' => count($sheet->json('data.rules.groups')), 'total' => (int) $sheet->json('data.rules.total')]);

        $attainment = $this->api('GET', 'classes/'.$class.'/attainment', $kavya)->assertOk()->json('data.outcomes.0');
        // CO1 on IA1–3: Asha 110/150 = 73% (attained), Bala 50/150 = 33%
        $this->assertSame([2, 1, 50.0], [$attainment['students'], $attainment['attained'], (float) $attainment['percentage']]);

        $this->api('POST', 'classes/'.$class.'/internal-marks/submit', $kavya)->assertOk()->assertJsonPath('data.status', 'submitted');
        $save = $this->api('PUT', 'assessments/'.$a1.'/marks', $kavya, ['class' => $class, 'marks' => [$asha => ['marks' => 9, 'status' => 'present']]])->assertStatus(409)->assertJsonPath('code', 'MARKS_LOCKED');
        $this->api('POST', 'offerings/'.$offering.'/assessment-groups', $kavya, ['name' => 'Late', 'weight' => 5])->assertStatus(409)->assertJsonPath('code', 'SCHEME_LOCKED');

        $sheetId = $this->api('GET', 'internal-mark-sheets', $admin)->assertOk()->assertJsonPath('data.0.can_verify', true)->json('data.0.id');
        $this->api('POST', 'internal-mark-sheets/'.$sheetId.'/verify', $kavya, ['approve' => true])->assertForbidden();
        $this->api('POST', 'internal-mark-sheets/'.$sheetId.'/lock', $admin)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATUS');
        $this->api('POST', 'internal-mark-sheets/'.$sheetId.'/verify', $admin, ['approve' => false])->assertStatus(422)->assertJsonPath('code', 'REMARKS_REQUIRED');
        $this->api('POST', 'internal-mark-sheets/'.$sheetId.'/verify', $admin, ['approve' => false, 'remarks' => 'Recheck IA2'])->assertOk();
        $this->api('GET', 'classes/'.$class.'/internal-marks', $kavya)->assertJsonPath('data.status', 'draft')->assertJsonPath('data.remarks', 'Recheck IA2');

        $this->api('POST', 'classes/'.$class.'/internal-marks/submit', $kavya)->assertOk();
        $this->api('GET', 'me/internal-marks', $this->activate('asha@example.edu'))->assertOk()->assertJsonPath('data.courses.0.internal', null)->assertJsonCount(4, 'data.courses.0.assessments');
        $this->api('POST', 'internal-mark-sheets/'.$sheetId.'/verify', $admin, ['approve' => true])->assertOk();
        $this->api('POST', 'internal-mark-sheets/'.$sheetId.'/lock', $admin)->assertOk();
        $this->api('GET', 'me/internal-marks', $this->activate('asha@example.edu'))->assertJsonPath('data.courses.0.internal.final', 33)->assertJsonPath('data.courses.0.internal.out_of', 40);
    }

    public function test_correction_after_submission_recomputes_the_student(): void
    {
        ['admin' => $admin, 'offering' => $offering, 'class' => $class, 'students' => $students] = $this->campus();
        $kavya = $this->activate('kavya@example.edu');
        $group = $this->api('POST', 'offerings/'.$offering.'/assessment-groups', $kavya, ['name' => 'Quiz', 'weight' => 10])->json('data.id');
        $quiz = $this->api('POST', 'assessment-groups/'.$group.'/assessments', $kavya, ['name' => 'Q1', 'kind' => 'quiz', 'max_marks' => 10])->json('data.id');
        $this->api('PUT', 'assessments/'.$quiz.'/marks', $kavya, ['class' => $class, 'marks' => [$students['asha']['id'] => ['marks' => 4, 'status' => 'present']]])->assertOk();
        $this->api('POST', 'classes/'.$class.'/internal-marks/submit', $kavya)->assertOk();

        $markId = $this->api('GET', 'assessments/'.$quiz.'/marks?class='.$class, $kavya)->assertJsonPath('data.editable', false)->json('data.students.0.mark_id');
        $this->api('POST', 'mark-corrections', $kavya, ['mark_id' => $markId, 'marks' => 8, 'status' => 'present', 'reason' => 'Totalling error'])->assertCreated();
        $this->api('POST', 'mark-corrections', $kavya, ['mark_id' => $markId, 'marks' => 9, 'status' => 'present', 'reason' => 'again'])->assertStatus(409)->assertJsonPath('code', 'CORRECTION_PENDING');

        $correction = $this->api('GET', 'mark-corrections?status=pending', $admin)->assertOk()->assertJsonPath('data.0.current.marks', 4)->json('data.0.id');
        $this->api('POST', 'mark-corrections/'.$correction.'/decide', $kavya, ['approve' => true])->assertForbidden();
        $this->api('POST', 'mark-corrections/'.$correction.'/decide', $admin, ['approve' => true])->assertOk();

        $this->api('GET', 'classes/'.$class.'/internal-marks', $kavya)->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.students.0.final', 8);
    }

    public function test_assignment_submission_late_rules_and_evaluation_feed_the_assessment(): void
    {
        ['offering' => $offering, 'class' => $class, 'students' => $students] = $this->campus();
        $kavya = $this->activate('kavya@example.edu');
        $asha = $this->activate('asha@example.edu');
        $bala = $this->activate('bala@example.edu');

        $group = $this->api('POST', 'offerings/'.$offering.'/assessment-groups', $kavya, ['name' => 'Assignment', 'weight' => 10])->json('data.id');
        $a1 = $this->api('POST', 'assessment-groups/'.$group.'/assessments', $kavya, ['name' => 'A1', 'kind' => 'assignment', 'max_marks' => 10])->json('data.id');

        $open = $this->api('POST', 'offerings/'.$offering.'/assignments', $kavya, ['title' => 'ER diagram', 'due_at' => now()->addDay()->toIso8601String(), 'max_marks' => 20, 'assessment_id' => $a1])->assertCreated()->json('data.id');
        $closed = $this->api('POST', 'offerings/'.$offering.'/assignments', $kavya, ['title' => 'Normalisation', 'due_at' => now()->subDay()->toIso8601String(), 'max_marks' => 10, 'allow_late' => false])->json('data.id');

        $this->api('GET', 'me/assignments', $asha)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.can_submit', false)->assertJsonPath('data.1.can_submit', true);
        $this->api('POST', 'me/assignments/'.$closed.'/submit', $asha, ['text' => 'late'])->assertStatus(409)->assertJsonPath('code', 'DEADLINE_PASSED');
        $this->api('POST', 'me/assignments/'.$open.'/submit', $asha, [])->assertStatus(422)->assertJsonPath('code', 'EMPTY_SUBMISSION');
        $this->api('POST', 'me/assignments/'.$open.'/submit', $asha, ['text' => 'My ER diagram'])->assertCreated()->assertJsonPath('data.is_late', false);

        $this->api('PATCH', 'assignments/'.$closed, $kavya, ['title' => 'Normalisation', 'due_at' => now()->subDay()->toIso8601String(), 'max_marks' => 10, 'allow_late' => true])->assertOk();
        $this->api('POST', 'me/assignments/'.$closed.'/submit', $bala, ['text' => 'BCNF'])->assertCreated()->assertJsonPath('data.is_late', true);

        $submission = $this->api('GET', 'assignments/'.$open.'/submissions', $kavya)->assertOk()->assertJsonCount(1, 'data.submissions')->json('data.submissions.0.id');
        $this->api('POST', 'assignment-submissions/'.$submission.'/evaluate', $kavya, ['marks' => 25])->assertStatus(422)->assertJsonPath('code', 'MARKS_OUT_OF_RANGE');
        $this->api('POST', 'assignment-submissions/'.$submission.'/evaluate', $kavya, ['marks' => 15, 'feedback' => 'Good'])->assertOk();

        // 15 / 20 scaled to A1's 10 marks
        $this->api('GET', 'assessments/'.$a1.'/marks?class='.$class, $kavya)->assertJsonPath('data.students.0.marks', 7.5);
        $this->api('GET', 'me/assignments', $asha)->assertJsonPath('data.1.submission.marks', 15)->assertJsonPath('data.1.submission.feedback', 'Good')->assertJsonPath('data.1.can_submit', false);
        $this->api('DELETE', 'assignments/'.$open, $kavya)->assertStatus(409);
    }
}
