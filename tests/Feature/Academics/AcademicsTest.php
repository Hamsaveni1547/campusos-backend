<?php

namespace Tests\Feature\Academics;

use App\Models\User;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Institution\Models\Institution;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AcademicsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Department, programme, a 2026 batch with sections A and B, a current term, and two courses with a syllabus.
     *
     * @return array<string, mixed>
     */
    private function setUpCampus(): array
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);

        $department = $this->api('POST', 'departments', $admin, ['code' => 'CSE', 'name' => 'Computer Science'])->json('data');
        $programme = $this->api('POST', 'programmes', $admin, ['department_id' => $department['id'], 'code' => 'BE-CSE', 'name' => 'B.E. Computer Science', 'level' => 'ug', 'duration_years' => 4])->json('data');
        $batch = $this->api('POST', 'batches', $admin, ['programme_id' => $programme['id'], 'admission_year' => 2026, 'sections' => ['A', 'B']])->json('data');
        $year = $this->api('POST', 'academic-years', $admin, ['name' => '2026-27', 'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonths(11)->toDateString()])->json('data');
        $term = $this->api('POST', 'terms', $admin, ['academic_year_id' => $year['id'], 'type' => 'odd', 'name' => 'Odd 2026', 'starts_on' => now()->subWeeks(2)->toDateString(), 'ends_on' => now()->addMonths(4)->toDateString()])->json('data');
        $this->api('POST', 'terms/'.$term['id'].'/make-current', $admin)->assertOk();

        $dbms = $this->api('POST', 'courses', $admin, ['department_id' => $department['id'], 'code' => '21cs51', 'title' => 'Database Management Systems', 'type' => 'theory', 'credits' => 4, 'lecture_hours' => 3, 'tutorial_hours' => 1, 'regulation' => '2021'])
            ->assertCreated()->assertJsonPath('data.code', '21CS51')->assertJsonPath('data.ltp', '3-1-0')->json('data');
        $lab = $this->api('POST', 'courses', $admin, ['department_id' => $department['id'], 'code' => '21CSL55', 'title' => 'DBMS Lab', 'type' => 'lab', 'credits' => 1, 'practical_hours' => 2])->json('data');

        $dbms = $this->api('PUT', 'courses/'.$dbms['id'].'/syllabus', $admin, [
            'units' => [
                ['title' => 'Introduction', 'hours' => 8, 'topics' => [['title' => 'Database concepts'], ['title' => 'ER model', 'hours' => 2]]],
                ['title' => 'Relational model', 'hours' => 8, 'topics' => [['title' => 'Relational algebra']]],
            ],
            'textbooks' => ['Elmasri & Navathe, Fundamentals of Database Systems'],
        ])->assertOk()->assertJsonPath('data.units.1.number', 2)->assertJsonPath('data.units.0.topics.1.hours', 2)->json('data');

        return compact('institution', 'admin', 'department', 'programme', 'batch', 'term', 'dbms', 'lab');
    }

    private function activate(User $user): User
    {
        $user->forceFill(['password' => Hash::make(UserFactory::PASSWORD), 'status' => UserStatus::Active, 'email_verified_at' => now(), 'must_change_password' => false])->save();

        return $user;
    }

    public function test_curriculum_versions_are_approved_and_offered_to_sections(): void
    {
        ['institution' => $institution, 'admin' => $admin, 'programme' => $programme, 'batch' => $batch, 'term' => $term, 'dbms' => $dbms, 'lab' => $lab] = $this->setUpCampus();
        $head = $this->member($institution, ['institution_head']);

        $v1 = $this->api('POST', 'curricula', $admin, ['programme_id' => $programme['id'], 'regulation' => '2021'])->assertCreated()->assertJsonPath('data.version', 1)->json('data');
        $this->api('POST', 'curricula/'.$v1['id'].'/submit', $admin)->assertStatus(422)->assertJsonPath('code', 'CURRICULUM_EMPTY');
        $this->api('PUT', 'curricula/'.$v1['id'].'/entries', $admin, ['entries' => [['course_id' => $dbms['id'], 'term_number' => 9]]])->assertStatus(422)->assertJsonPath('code', 'TERM_OUT_OF_RANGE');
        $this->api('PUT', 'curricula/'.$v1['id'].'/entries', $admin, ['entries' => [
            ['course_id' => $dbms['id'], 'term_number' => 1, 'category' => 'core'],
            ['course_id' => $lab['id'], 'term_number' => 1, 'category' => 'lab'],
        ]])->assertOk()->assertJsonPath('data.credits_by_term.1', 5);

        $this->api('POST', 'curricula/'.$v1['id'].'/submit', $admin)->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->api('PUT', 'curricula/'.$v1['id'].'/entries', $admin, ['entries' => []])->assertStatus(409)->assertJsonPath('code', 'CURRICULUM_LOCKED');
        $this->api('POST', 'curricula/'.$v1['id'].'/decide', $head, ['approve' => false])->assertStatus(422);
        $this->api('POST', 'curricula/'.$v1['id'].'/decide', $head, ['approve' => false, 'remarks' => 'Add the lab hours'])->assertOk()->assertJsonPath('data.status', 'draft');
        $this->api('POST', 'curricula/'.$v1['id'].'/submit', $admin)->assertOk();
        $this->api('POST', 'curricula/'.$v1['id'].'/decide', $head, ['approve' => true])->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approved_by', $head->name);

        $v2 = $this->api('POST', 'curricula', $admin, ['programme_id' => $programme['id'], 'regulation' => '2021', 'copy_from' => $v1['id']])->assertCreated()
            ->assertJsonPath('data.version', 2)->assertJsonCount(2, 'data.entries')->json('data');
        $this->api('POST', 'curricula/'.$v2['id'].'/submit', $admin);
        $this->api('POST', 'curricula/'.$v2['id'].'/decide', $admin, ['approve' => true])->assertOk();
        $this->api('GET', 'curricula/'.$v1['id'], $admin)->assertJsonPath('data.status', 'archived');

        $sections = array_column($batch['sections'], 'id');
        $this->api('POST', 'course-offerings/generate', $admin, ['curriculum_id' => $v2['id'], 'term_id' => $term['id'], 'term_number' => 1, 'section_ids' => $sections])
            ->assertOk()->assertJsonPath('data.created', 2);
        $this->api('POST', 'course-offerings/generate', $admin, ['curriculum_id' => $v2['id'], 'term_id' => $term['id'], 'term_number' => 1, 'section_ids' => $sections])
            ->assertOk()->assertJsonPath('data.skipped', 2);

        $this->api('GET', 'course-offerings', $admin)->assertOk()->assertJsonCount(2, 'data')->assertJsonCount(2, 'data.0.sections');
        $this->api('DELETE', 'courses/'.$dbms['id'], $admin)->assertStatus(409)->assertJsonPath('code', 'IN_USE');
    }

    public function test_allocation_teaching_progress_materials_and_student_view(): void
    {
        ['institution' => $institution, 'admin' => $admin, 'programme' => $programme, 'batch' => $batch, 'term' => $term, 'dbms' => $dbms] = $this->setUpCampus();
        $this->setting($institution, 'academics', ['max_weekly_hours' => 4]);
        [$sectionA, $sectionB] = array_column($batch['sections'], 'id');

        $offering = $this->api('POST', 'course-offerings', $admin, ['course_id' => $dbms['id'], 'term_id' => $term['id'], 'section_ids' => [$sectionA, $sectionB]])->assertCreated()->json('data');
        $classA = $offering['sections'][0]['id'];
        $classB = $offering['sections'][1]['id'];

        $teacher = $this->api('POST', 'employees', $admin, ['name' => 'Dr. Kavya', 'email' => 'kavya@example.edu', 'staff_type' => 'teaching', 'employment_type' => 'permanent', 'joined_on' => '2020-06-01', 'send_invite' => false])->json('data');
        $clerk = $this->api('POST', 'employees', $admin, ['name' => 'Office Clerk', 'email' => 'clerk@example.edu', 'staff_type' => 'non_teaching', 'employment_type' => 'permanent', 'joined_on' => '2020-06-01', 'send_invite' => false])->json('data');
        $other = $this->api('POST', 'employees', $admin, ['name' => 'Dr. Ravi', 'email' => 'ravi@example.edu', 'staff_type' => 'teaching', 'employment_type' => 'permanent', 'joined_on' => '2020-06-01', 'send_invite' => false])->json('data');

        $this->api('POST', 'offering-sections/'.$classA.'/allocations', $admin, ['employee_id' => $clerk['id'], 'component' => 'theory', 'weekly_hours' => 3])->assertStatus(422)->assertJsonPath('code', 'FACULTY_NOT_ELIGIBLE');
        $this->api('POST', 'offering-sections/'.$classA.'/allocations', $admin, ['employee_id' => $teacher['id'], 'component' => 'theory', 'weekly_hours' => 3])->assertCreated()->assertJsonPath('data.warning', null);
        $this->api('POST', 'offering-sections/'.$classA.'/allocations', $admin, ['employee_id' => $teacher['id'], 'component' => 'theory', 'weekly_hours' => 3])->assertStatus(409);
        $this->api('POST', 'offering-sections/'.$classB.'/allocations', $admin, ['employee_id' => $teacher['id'], 'component' => 'theory', 'weekly_hours' => 3])->assertCreated()
            ->assertJsonPath('data.warning', 'Dr. Kavya now teaches 6 hours a week (limit 4).');

        $this->api('GET', 'faculty-workload', $admin)->assertOk()->assertJsonPath('data.faculty.0.weekly_hours', 6)->assertJsonPath('data.faculty.0.over_limit', true);
        $this->api('GET', 'course-offerings?filter[unallocated]=1', $admin)->assertJsonCount(0, 'data');

        $kavya = $this->activate(User::query()->where('email', 'kavya@example.edu')->firstOrFail());
        $ravi = $this->activate(User::query()->where('email', 'ravi@example.edu')->firstOrFail());

        $this->api('GET', 'me/teaching', $kavya)->assertOk()->assertJsonPath('data.weekly_hours', 6)->assertJsonCount(2, 'data.classes');

        $topic = $this->api('GET', 'offering-sections/'.$classA, $kavya)->assertOk()->assertJsonPath('data.can_teach', true)->json('data.units.0.topics.0.id');
        $this->api('POST', 'offering-sections/'.$classA.'/coverage', $ravi, ['topic_id' => $topic])->assertForbidden();
        $this->api('POST', 'offering-sections/'.$classA.'/coverage', $kavya, ['topic_id' => $topic, 'notes' => 'With examples'])->assertOk()
            ->assertJsonPath('data.coverage_percent', 33)
            ->assertJsonPath('data.units.0.topics.0.covered_by', 'Dr. Kavya');

        $this->api('PUT', 'courses/'.$dbms['id'].'/syllabus', $admin, ['units' => []])->assertStatus(409)->assertJsonPath('code', 'SYLLABUS_IN_USE');

        $fileId = $this->api('POST', 'files', $kavya, ['file' => UploadedFile::fake()->create('unit1.pdf', 80, 'application/pdf'), 'purpose' => 'document'])->json('data.id');
        $this->api('POST', 'offering-sections/'.$classA.'/materials', $kavya, ['title' => 'Unit 1 notes', 'file_id' => $fileId])->assertOk()->assertJsonPath('data.materials.0.title', 'Unit 1 notes');
        $this->api('POST', 'offering-sections/'.$classA.'/materials', $kavya, ['title' => 'Draft quiz', 'url' => 'https://example.edu/quiz', 'is_published' => false])->assertOk();

        $studentA = $this->api('POST', 'students', $admin, ['name' => 'Asha', 'email' => 'asha@example.edu', 'programme_id' => $programme['id'], 'admission_type' => 'cet', 'admitted_on' => now()->toDateString(), 'batch_id' => $batch['id'], 'section_id' => $sectionA, 'send_invite' => false])->assertCreated();
        unset($studentA);
        $asha = $this->activate(User::query()->where('email', 'asha@example.edu')->firstOrFail());

        $this->api('GET', 'me/courses', $asha)->assertOk()->assertJsonPath('data.courses.0.course.code', '21CS51')->assertJsonPath('data.courses.0.faculty.0.name', 'Dr. Kavya');
        $this->api('GET', 'offering-sections/'.$classA, $asha)->assertOk()->assertJsonPath('data.can_teach', false)->assertJsonCount(1, 'data.materials');
        $this->api('GET', 'offering-sections/'.$classB, $asha)->assertNotFound();
        $this->api('POST', 'offering-sections/'.$classA.'/coverage', $asha, ['topic_id' => $topic])->assertForbidden();

        $this->api('PATCH', 'course-offerings/'.$offering['id'], $admin, ['section_ids' => [$sectionB]])->assertStatus(409)->assertJsonPath('code', 'SECTION_IN_USE');
    }

    public function test_term_registration_with_electives_capacity_credits_and_approval(): void
    {
        ['institution' => $institution, 'admin' => $admin, 'department' => $department, 'programme' => $programme, 'batch' => $batch, 'term' => $term, 'dbms' => $dbms] = $this->setUpCampus();
        $sectionA = $batch['sections'][0]['id'];

        $ai = $this->api('POST', 'courses', $admin, ['department_id' => $department['id'], 'code' => '21CS561', 'title' => 'Artificial Intelligence', 'type' => 'theory', 'credits' => 3])->json('data');
        $iot = $this->api('POST', 'courses', $admin, ['department_id' => $department['id'], 'code' => '21CS562', 'title' => 'Internet of Things', 'type' => 'theory', 'credits' => 3])->json('data');
        $this->api('POST', 'course-offerings', $admin, ['course_id' => $dbms['id'], 'term_id' => $term['id'], 'section_ids' => [$sectionA]])->assertCreated();
        $aiOffering = $this->api('POST', 'course-offerings', $admin, ['course_id' => $ai['id'], 'term_id' => $term['id'], 'section_ids' => [$sectionA], 'is_elective' => true, 'capacity' => 1])->json('data');
        $iotOffering = $this->api('POST', 'course-offerings', $admin, ['course_id' => $iot['id'], 'term_id' => $term['id'], 'section_ids' => [$sectionA], 'is_elective' => true])->json('data');

        $students = [];
        foreach (['asha', 'bala'] as $name) {
            $this->api('POST', 'students', $admin, ['name' => ucfirst($name), 'email' => "{$name}@example.edu", 'programme_id' => $programme['id'], 'admission_type' => 'cet', 'admitted_on' => now()->toDateString(), 'batch_id' => $batch['id'], 'section_id' => $sectionA, 'send_invite' => false])->assertCreated();
            $students[$name] = $this->activate(User::query()->where('email', "{$name}@example.edu")->firstOrFail());
        }

        $this->api('GET', 'me/term-registration', $students['asha'])->assertOk()
            ->assertJsonPath('data.can_register', true)
            ->assertJsonPath('data.core.0.course.code', '21CS51')
            ->assertJsonCount(2, 'data.electives')
            ->assertJsonPath('data.electives.0.seats_left', 1);

        $this->setting($institution, 'academics', ['max_credits_per_term' => 8]);
        $this->api('POST', 'me/term-registration', $students['asha'], ['elective_offering_ids' => [$aiOffering['id'], $iotOffering['id']]])->assertStatus(422)->assertJsonPath('code', 'CREDIT_LIMIT');
        $this->setting($institution, 'academics', ['max_credits_per_term' => 30]);

        $this->api('POST', 'me/term-registration', $students['asha'], ['elective_offering_ids' => [$aiOffering['id']]])->assertOk()
            ->assertJsonPath('data.registration.status', 'submitted')
            ->assertJsonPath('data.registration.total_credits', 7)
            ->assertJsonPath('data.can_register', false);
        $this->api('POST', 'me/term-registration', $students['asha'], ['elective_offering_ids' => []])->assertStatus(409)->assertJsonPath('code', 'ALREADY_REGISTERED');
        $this->api('POST', 'me/term-registration', $students['bala'], ['elective_offering_ids' => [$aiOffering['id']]])->assertStatus(409)->assertJsonPath('code', 'ELECTIVE_FULL');
        $this->api('POST', 'me/term-registration', $students['bala'], ['elective_offering_ids' => [$iotOffering['id']]])->assertOk();

        $pending = $this->api('GET', 'term-registrations?filter[status]=submitted', $admin)->assertOk()->assertJsonPath('meta.total', 2)->json('data');
        $asha = collect($pending)->firstWhere('student.name', 'Asha');
        $bala = collect($pending)->firstWhere('student.name', 'Bala');

        $this->api('POST', 'term-registrations/decide', $admin, ['ids' => [$bala['id']], 'approve' => false])->assertStatus(422);
        $this->api('POST', 'term-registrations/decide', $admin, ['ids' => [$bala['id']], 'approve' => false, 'remarks' => 'Pick AI instead'])->assertOk();
        $this->api('POST', 'term-registrations/decide', $admin, ['ids' => [$asha['id']], 'approve' => true])->assertOk()->assertJsonPath('data.decided', 1);
        $this->api('GET', 'me/term-registration', $students['bala'])->assertJsonPath('data.registration.status', 'rejected')->assertJsonPath('data.can_register', true);
        $this->api('POST', 'me/term-registration', $students['bala'], ['elective_offering_ids' => [$iotOffering['id']]])->assertOk()->assertJsonPath('data.registration.status', 'submitted');

        $class = $aiOffering['sections'][0]['id'];
        $this->api('GET', 'offering-sections/'.$class.'/students', $admin)->assertOk()->assertJsonCount(1, 'data.students')->assertJsonPath('data.students.0.name', 'Asha');
        $this->api('GET', 'offering-sections/'.$class.'/students', $students['bala'])->assertForbidden();
    }

    public function test_permissions_and_tenancy(): void
    {
        ['admin' => $admin, 'dbms' => $dbms] = $this->setUpCampus();
        $institution = Institution::query()->firstOrFail();
        $faculty = $this->member($institution, ['faculty'], ['email' => 'f@example.edu']);
        $applicant = $this->member($institution, ['applicant'], ['email' => 'a@example.edu']);

        $this->api('GET', 'courses', $faculty)->assertOk()->assertJsonPath('meta.total', 2);
        $this->api('POST', 'courses', $faculty, ['code' => 'X1'])->assertForbidden();
        $this->api('GET', 'courses', $applicant)->assertForbidden();
        $this->api('GET', 'academics/options', $applicant)->assertOk()->assertJsonPath('data.course_types.0.value', 'theory');

        $outsider = $this->member($this->institution(['code' => 'BMS']), ['institution_admin'], ['email' => 'admin@bms.edu']);
        $this->api('GET', 'courses/'.$dbms['id'], $outsider)->assertNotFound();
        unset($admin);
    }
}
