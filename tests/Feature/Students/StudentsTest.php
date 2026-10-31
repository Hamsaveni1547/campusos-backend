<?php

namespace Tests\Feature\Students;

use App\Models\User;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Institution\Models\Department;
use App\Modules\Institution\Models\Institution;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StudentsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Two departments with one programme each; CSE has a 2026 batch with section A.
     *
     * @return array{institution: Institution, admin: User, cse: array<string, mixed>, ece: array<string, mixed>, batch: array<string, mixed>, section: string}
     */
    private function structure(): array
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);

        $cseDept = $this->api('POST', 'departments', $admin, ['code' => 'CSE', 'name' => 'Computer Science'])->json('data');
        $eceDept = $this->api('POST', 'departments', $admin, ['code' => 'ECE', 'name' => 'Electronics'])->json('data');
        $cse = $this->api('POST', 'programmes', $admin, ['department_id' => $cseDept['id'], 'code' => 'BE-CSE', 'name' => 'B.E. Computer Science', 'level' => 'ug', 'duration_years' => 4])->json('data');
        $ece = $this->api('POST', 'programmes', $admin, ['department_id' => $eceDept['id'], 'code' => 'BE-ECE', 'name' => 'B.E. Electronics', 'level' => 'ug', 'duration_years' => 4])->json('data');
        $batch = $this->api('POST', 'batches', $admin, ['programme_id' => $cse['id'], 'admission_year' => 2026, 'sections' => ['A']])->json('data');

        return ['institution' => $institution, 'admin' => $admin, 'cse' => $cse, 'ece' => $ece, 'batch' => $batch, 'section' => $batch['sections'][0]['id']];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function addStudent(User $staff, string $programme, string $email, array $extra = []): array
    {
        return $this->api('POST', 'students', $staff, array_merge([
            'name' => 'Student '.$email, 'email' => $email, 'programme_id' => $programme, 'admission_type' => 'cet', 'admitted_on' => '2026-08-01', 'send_invite' => false,
        ], $extra))->assertCreated()->json('data');
    }

    public function test_staff_add_list_edit_and_place_students(): void
    {
        ['admin' => $admin, 'cse' => $cse, 'batch' => $batch, 'section' => $section] = $this->structure();

        $student = $this->addStudent($admin, $cse['id'], 'ravi@example.edu', [
            'date_of_birth' => '2008-03-10', 'gender' => 'male', 'id_proof_type' => 'aadhaar', 'id_proof_number' => '123412341234',
            'guardians' => [['relation' => 'father', 'name' => 'Suresh', 'phone' => '9876543210'], ['relation' => 'mother', 'name' => 'Lata', 'is_primary' => true]],
        ]);

        $this->assertSame('active', $student['status']);
        $this->assertSame($batch['id'], $student['batch']['id']);
        $this->assertStringContainsString('BECSE', $student['register_no']);
        $this->assertSame('Lata', collect($student['guardians'])->firstWhere('is_primary', true)['name']);
        $this->assertSame('123412341234', $student['personal']['id_proof_number']);

        $this->api('POST', 'students', $admin, ['name' => 'Dup', 'email' => 'ravi@example.edu', 'programme_id' => $cse['id'], 'admission_type' => 'cet', 'admitted_on' => '2026-08-01'])
            ->assertStatus(409)->assertJsonPath('code', 'STUDENT_EXISTS');

        $this->api('GET', 'students?filter[search]=ravi', $admin)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $student['id']);
        $this->api('PATCH', 'students/'.$student['id'], $admin, ['blood_group' => 'O+', 'name' => 'Ravi Kumar'])->assertOk()
            ->assertJsonPath('data.name', 'Ravi Kumar')->assertJsonPath('data.personal.blood_group', 'O+');
        $this->api('PATCH', 'students/'.$student['id'], $admin, ['register_no' => 'NEW1'])->assertStatus(409)->assertJsonPath('code', 'REGISTER_NO_LOCKED');

        $this->api('POST', 'students/'.$student['id'].'/placement', $admin, ['batch_id' => $batch['id'], 'section_id' => $section, 'roll_no' => '01', 'current_term_number' => 1])
            ->assertOk()->assertJsonPath('data.section.name', 'A')->assertJsonPath('data.roll_no', '01');

        $other = $this->addStudent($admin, $cse['id'], 'asha@example.edu');
        $this->api('POST', 'students/'.$other['id'].'/placement', $admin, ['batch_id' => $batch['id'], 'section_id' => $section, 'roll_no' => '01'])
            ->assertStatus(409)->assertJsonPath('code', 'ROLL_NO_TAKEN');
        $this->api('POST', 'students/'.$other['id'].'/placement', $admin, ['section_id' => $section])
            ->assertStatus(422)->assertJsonPath('code', 'SECTION_MISMATCH');

        $this->api('GET', 'students/summary', $admin)->assertOk()->assertJsonPath('data.by_status.active', 2);
    }

    public function test_status_lifecycle_and_exit_removes_student_access(): void
    {
        ['institution' => $institution, 'admin' => $admin, 'cse' => $cse] = $this->structure();
        $student = $this->addStudent($admin, $cse['id'], 'meera@example.edu');
        $user = User::query()->where('email', 'meera@example.edu')->firstOrFail();

        $this->api('POST', 'students/'.$student['id'].'/status', $admin, ['status' => 'on_leave', 'reason' => 'Medical'])->assertOk()->assertJsonPath('data.status', 'on_leave');
        $this->api('POST', 'students/'.$student['id'].'/status', $admin, ['status' => 'detained'])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATUS');
        $this->api('POST', 'students/'.$student['id'].'/status', $admin, ['status' => 'active'])->assertOk();
        $this->api('POST', 'students/'.$student['id'].'/status', $admin, ['status' => 'dropped_out'])->assertStatus(422)->assertJsonPath('code', 'REASON_REQUIRED');

        $this->api('POST', 'students/'.$student['id'].'/status', $admin, ['status' => 'dropped_out', 'reason' => 'Joined another college', 'effective_on' => '2026-12-01'])
            ->assertOk()
            ->assertJsonPath('data.left_on', '2026-12-01')
            ->assertJsonPath('data.status_history.3.to', 'dropped_out');

        $this->assertFalse($user->fresh()->roleAssignments()->effective()->whereHas('role', fn ($query) => $query->where('name', 'student'))->exists());
        $this->api('POST', 'students/'.$student['id'].'/status', $admin, ['status' => 'active'])->assertStatus(409);
        $this->api('GET', 'students', $admin)->assertJsonPath('meta.total', 0);
        $this->api('GET', 'students?filter[status]=all', $admin)->assertJsonPath('meta.total', 1);
        unset($institution);
    }

    public function test_heads_of_department_and_faculty_see_only_their_department(): void
    {
        ['institution' => $institution, 'admin' => $admin, 'cse' => $cse, 'ece' => $ece] = $this->structure();
        $cseStudent = $this->addStudent($admin, $cse['id'], 'c1@example.edu');
        $eceStudent = $this->addStudent($admin, $ece['id'], 'e1@example.edu');

        $cseDepartmentId = Department::query()->where('code', 'CSE')->value('id');
        $hod = $this->member($institution, [], ['email' => 'hod@example.edu']);
        $this->grantRole($hod, $institution, 'hod', $cseDepartmentId);
        $faculty = $this->member($institution, [], ['email' => 'fac@example.edu']);
        $this->grantRole($faculty, $institution, 'faculty', $cseDepartmentId);
        $head = $this->member($institution, ['institution_head']);

        $this->api('GET', 'students', $hod)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $cseStudent['id']);
        $this->api('GET', 'students/'.$eceStudent['id'], $hod)->assertNotFound();
        $this->api('GET', 'students/'.$cseStudent['id'], $faculty)->assertOk()->assertJsonPath('data.personal.id_proof_number', null);
        $this->api('PATCH', 'students/'.$cseStudent['id'], $faculty, ['blood_group' => 'A+'])->assertForbidden();
        $this->api('GET', 'students', $head)->assertOk()->assertJsonPath('meta.total', 2);

        $outsider = $this->member($this->institution(['code' => 'BMS']), ['institution_admin'], ['email' => 'admin@bms.edu']);
        $this->api('GET', 'students/'.$cseStudent['id'], $outsider)->assertNotFound();
    }

    public function test_student_self_service_documents_and_change_requests(): void
    {
        ['institution' => $institution, 'admin' => $admin, 'cse' => $cse] = $this->structure();
        $record = $this->addStudent($admin, $cse['id'], 'kiran@example.edu', ['date_of_birth' => '2008-01-01', 'category' => 'GM']);
        $student = User::query()->where('email', 'kiran@example.edu')->firstOrFail();
        // The account was created without an invite; give it the test password so it can sign in.
        $student->forceFill(['password' => Hash::make(UserFactory::PASSWORD), 'status' => UserStatus::Active, 'email_verified_at' => now(), 'must_change_password' => false])->save();
        $this->api('GET', 'me/student', $admin)->assertNotFound()->assertJsonPath('code', 'NOT_A_STUDENT');

        $this->api('GET', 'me/student', $student)->assertOk()->assertJsonPath('data.register_no', $record['register_no'])->assertJsonPath('data.self_editable.0', 'address');
        $this->api('PATCH', 'me/student', $student, ['address' => '12, MG Road, Bengaluru', 'date_of_birth' => '2001-01-01'])->assertOk()
            ->assertJsonPath('data.personal.address', '12, MG Road, Bengaluru')
            ->assertJsonPath('data.personal.date_of_birth', '2008-01-01');

        $fileId = $this->api('POST', 'files', $student, ['file' => UploadedFile::fake()->create('tc.pdf', 50, 'application/pdf'), 'purpose' => 'document'])->json('data.id');
        $document = $this->api('POST', 'me/student/documents', $student, ['document_type' => 'transfer_certificate', 'file_id' => $fileId])->assertOk()
            ->assertJsonPath('data.documents.0.status', 'pending')->json('data.documents.0.id');
        $this->api('POST', 'students/'.$record['id'].'/documents/'.$document.'/review', $admin, ['approved' => false])->assertStatus(422);
        $this->api('POST', 'students/'.$record['id'].'/documents/'.$document.'/review', $admin, ['approved' => true])->assertOk()->assertJsonPath('data.documents.0.status', 'approved');

        $this->api('POST', 'me/student/change-requests', $student, ['changes' => ['date_of_birth' => '2008-02-01', 'category' => 'SC'], 'reason' => 'Typo in birth date'])->assertOk()
            ->assertJsonPath('data.change_requests.0.status', 'pending');
        $this->api('POST', 'me/student/change-requests', $student, ['changes' => ['gender' => 'female'], 'reason' => 'x'])->assertStatus(409)->assertJsonPath('code', 'REQUEST_PENDING');

        $request = $this->api('GET', 'student-change-requests', $admin)->assertOk()->assertJsonPath('data.0.student.name', 'Student kiran@example.edu')->json('data.0.id');
        $this->api('POST', 'student-change-requests/'.$request.'/review', $admin, ['approved' => true])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->api('POST', 'student-change-requests/'.$request.'/review', $admin, ['approved' => true])->assertStatus(409);

        $this->api('GET', 'me/student', $student)->assertJsonPath('data.personal.date_of_birth', '2008-02-01')->assertJsonPath('data.category', 'SC');
        unset($institution);
    }

    public function test_import_existing_students(): void
    {
        ['admin' => $admin, 'batch' => $batch] = $this->structure();

        $csv = "name,email,programme_code,admission_type,admitted_on,register_no,batch,section,roll_no,guardian_name\n"
            ."Anil,anil@example.edu,BE-CSE,cet,2026-08-01,1RV26CS001,{$batch['name']},A,01,Ramesh\n"
            ."Bina,bina@example.edu,BE-XYZ,cet,2026-08-01,,,,,\n";
        $fileId = $this->api('POST', 'files', $admin, ['file' => UploadedFile::fake()->createWithContent('students.csv', $csv), 'purpose' => 'import'])->json('data.id');

        $import = $this->api('POST', 'imports', $admin, ['type' => 'students', 'file_id' => $fileId, 'options' => ['send_invite' => false]])->assertStatus(202)->json('data.id');
        $this->api('GET', 'imports/'.$import, $admin)->assertJsonPath('data.status', 'invalid')->assertJsonPath('data.errors.0.row', 3);

        $good = "name,email,programme_code,admission_type,admitted_on,register_no,batch,section,roll_no,guardian_name\nAnil,anil@example.edu,BE-CSE,cet,2026-08-01,1RV26CS001,{$batch['name']},A,01,Ramesh\n";
        $fileId = $this->api('POST', 'files', $admin, ['file' => UploadedFile::fake()->createWithContent('students.csv', $good), 'purpose' => 'import'])->json('data.id');
        $import = $this->api('POST', 'imports', $admin, ['type' => 'students', 'file_id' => $fileId, 'options' => ['send_invite' => false]])->json('data.id');
        $this->api('POST', 'imports/'.$import.'/commit', $admin)->assertSuccessful();

        $this->api('GET', 'students?filter[search]=1RV26CS001', $admin)->assertJsonPath('data.0.section.name', 'A')->assertJsonPath('data.0.roll_no', '01');
    }
}
