<?php

namespace Tests\Feature\Institution;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicStructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_departments_and_programmes(): void
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $faculty = $this->member($institution, ['faculty']);

        $this->api('POST', 'departments', $faculty, ['code' => 'CSE', 'name' => 'Computer Science'])->assertForbidden();

        $cse = $this->api('POST', 'departments', $admin, ['code' => 'CSE', 'name' => 'Computer Science and Engineering'])
            ->assertCreated()->assertJsonPath('data.status', 'active')->json('data');
        $this->api('POST', 'departments', $admin, ['code' => 'CSE', 'name' => 'Duplicate'])->assertStatus(422)->assertJsonValidationErrors('code');

        $programme = $this->api('POST', 'programmes', $admin, [
            'department_id' => $cse['id'], 'code' => 'BE-CSE', 'name' => 'B.E. Computer Science', 'level' => 'ug', 'duration_years' => 4, 'regulation' => '2022 Scheme',
        ])->assertCreated()
            ->assertJsonPath('data.total_terms', 8)
            ->assertJsonPath('data.department.code', 'CSE')
            ->json('data');

        // Every member can read the structure.
        $this->api('GET', 'departments', $faculty)->assertOk()->assertJsonPath('data.0.programmes_count', 1);
        $this->api('GET', 'programmes?filter[department]='.$cse['id'], $faculty)->assertOk()->assertJsonCount(1, 'data');

        // Department with active programmes cannot be deactivated or deleted.
        $this->api('PATCH', 'departments/'.$cse['id'], $admin, ['status' => 'inactive'])->assertStatus(409)->assertJsonPath('code', 'DEPARTMENT_HAS_ACTIVE_PROGRAMMES');
        $this->api('DELETE', 'departments/'.$cse['id'], $admin)->assertStatus(409)->assertJsonPath('code', 'IN_USE');

        $this->api('PATCH', 'programmes/'.$programme['id'], $admin, ['status' => 'inactive'])->assertOk();
        $this->api('PATCH', 'departments/'.$cse['id'], $admin, ['status' => 'inactive'])->assertOk();

        $this->api('POST', 'programmes', $admin, [
            'department_id' => $cse['id'], 'code' => 'ME-CSE', 'name' => 'M.Tech', 'level' => 'pg', 'duration_years' => 2,
        ])->assertStatus(422)->assertJsonPath('code', 'DEPARTMENT_INVALID');
    }

    public function test_structure_of_other_institutions_is_invisible(): void
    {
        $mine = $this->institution();
        $theirs = $this->institution();
        $admin = $this->member($mine, ['institution_admin']);
        $otherAdmin = $this->member($theirs, ['institution_admin']);

        $foreign = $this->api('POST', 'departments', $otherAdmin, ['code' => 'MECH', 'name' => 'Mechanical'])->json('data');

        $this->api('GET', 'departments', $admin)->assertOk()->assertJsonCount(0, 'data');
        $this->api('GET', 'departments/'.$foreign['id'], $admin)->assertNotFound();
        $this->api('PATCH', 'departments/'.$foreign['id'], $admin, ['name' => 'Hacked'])->assertNotFound();

        // Same code is fine in another institution.
        $this->api('POST', 'departments', $admin, ['code' => 'MECH', 'name' => 'Mechanical'])->assertCreated();
    }

    public function test_academic_years_and_terms(): void
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);

        $year = $this->api('POST', 'academic-years', $admin, ['name' => '2026-27', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'])
            ->assertCreated()->assertJsonPath('data.status', 'planned')->json('data');

        $this->api('POST', 'academic-years', $admin, ['name' => '2027-28', 'starts_on' => '2027-06-01', 'ends_on' => '2028-05-31'])
            ->assertStatus(409)->assertJsonPath('code', 'ACADEMIC_YEAR_OVERLAP');

        $odd = $this->api('POST', 'terms', $admin, ['academic_year_id' => $year['id'], 'type' => 'odd', 'name' => 'Odd Semester', 'starts_on' => '2026-08-01', 'ends_on' => '2026-12-15'])
            ->assertCreated()->assertJsonPath('data.is_current', false)->json('data');

        // Terms inside a year keep every field, including is_current = false, and leave out the year.
        $listedTerm = $this->api('GET', 'academic-years', $admin)->assertOk()->json('data.0.terms.0');
        $this->assertFalse($listedTerm['is_current']);
        $this->assertArrayNotHasKey('academic_year', $listedTerm);

        $this->api('POST', 'terms', $admin, ['academic_year_id' => $year['id'], 'type' => 'even', 'name' => 'Even', 'starts_on' => '2026-12-01', 'ends_on' => '2027-05-15'])
            ->assertStatus(409)->assertJsonPath('code', 'TERM_OVERLAP');
        $this->api('POST', 'terms', $admin, ['academic_year_id' => $year['id'], 'type' => 'even', 'name' => 'Even', 'starts_on' => '2027-01-02', 'ends_on' => '2027-07-30'])
            ->assertStatus(422)->assertJsonPath('code', 'TERM_OUTSIDE_YEAR');

        $this->api('GET', 'academic-context', $admin)->assertOk()->assertJsonPath('data.term', null);

        $this->api('POST', 'terms/'.$odd['id'].'/make-current', $admin)->assertOk()->assertJsonPath('data.is_current', true);

        $this->api('GET', 'academic-context', $admin)->assertOk()
            ->assertJsonPath('data.academic_year.name', '2026-27')
            ->assertJsonPath('data.academic_year.is_current', true)
            ->assertJsonPath('data.term.name', 'Odd Semester');

        $this->api('DELETE', 'terms/'.$odd['id'], $admin)->assertStatus(409);
        $this->api('POST', 'academic-years/'.$year['id'].'/close', $admin)->assertStatus(409)->assertJsonPath('code', 'OPEN_TERMS');

        $this->api('POST', 'terms/'.$odd['id'].'/close', $admin)->assertOk()->assertJsonPath('data.status', 'closed');
        $this->api('PATCH', 'terms/'.$odd['id'], $admin, ['name' => 'Renamed'])->assertStatus(409)->assertJsonPath('code', 'PERIOD_CLOSED');
        $this->api('POST', 'academic-years/'.$year['id'].'/close', $admin)->assertOk()->assertJsonPath('data.status', 'closed');
    }

    public function test_batches_and_sections(): void
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $advisor = $this->member($institution, ['faculty']);
        $outsider = $this->member($this->institution(), ['faculty']);

        $dept = $this->api('POST', 'departments', $admin, ['code' => 'ECE', 'name' => 'Electronics'])->json('data');
        $prog = $this->api('POST', 'programmes', $admin, ['department_id' => $dept['id'], 'code' => 'BE-ECE', 'name' => 'B.E. ECE', 'level' => 'ug', 'duration_years' => 4])->json('data');

        $batch = $this->api('POST', 'batches', $admin, ['programme_id' => $prog['id'], 'admission_year' => 2026, 'sections' => ['a', 'b']])
            ->assertCreated()
            ->assertJsonPath('data.name', 'BE-ECE 2026')
            ->assertJsonPath('data.graduation_year', 2030)
            ->assertJsonPath('data.sections.0.name', 'A')
            ->json('data');

        $this->api('POST', 'batches', $admin, ['programme_id' => $prog['id'], 'admission_year' => 2026])->assertStatus(409);
        $this->api('POST', "batches/{$batch['id']}/sections", $admin, ['name' => 'A'])->assertStatus(422);

        $section = $this->api('POST', "batches/{$batch['id']}/sections", $admin, ['name' => 'C', 'capacity' => 60, 'class_advisor_id' => $advisor->ulid])
            ->assertCreated()->assertJsonPath('data.class_advisor.name', $advisor->name)->json('data');

        $this->api('PATCH', "sections/{$section['id']}", $admin, ['class_advisor_id' => $outsider->ulid])->assertStatus(422)->assertJsonPath('code', 'ADVISOR_INVALID');

        $this->api('GET', 'batches?filter[department]='.$dept['id'], $advisor)->assertOk()->assertJsonCount(3, 'data.0.sections');
        $this->api('DELETE', 'programmes/'.$prog['id'], $admin)->assertStatus(409);
    }

    public function test_rooms_and_calendar(): void
    {
        $institution = $this->institution();
        $staff = $this->member($institution, ['admin_staff']);
        $student = $this->member($institution, ['student']);

        $this->api('POST', 'rooms', $staff, ['code' => 'LH-101', 'name' => 'Lecture Hall 1', 'building' => 'Main Block', 'type' => 'classroom', 'capacity' => 120])
            ->assertCreated();
        $this->api('POST', 'rooms', $staff, ['code' => 'CS-LAB1', 'type' => 'laboratory', 'capacity' => 40])->assertCreated();
        $this->api('POST', 'rooms', $staff, ['code' => 'LH-101', 'type' => 'classroom', 'capacity' => 10])->assertStatus(422);

        $this->api('GET', 'rooms?filter[type]=laboratory', $student)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);
        $this->api('GET', 'rooms?filter[min_capacity]=100', $student)->assertOk()->assertJsonPath('data.0.code', 'LH-101');

        $this->api('POST', 'academic-years', $staff, ['name' => '2026-27', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'])->assertCreated();

        $this->api('POST', 'calendar-events', $staff, ['title' => 'Deepavali', 'type' => 'holiday', 'starts_on' => '2026-11-08'])
            ->assertCreated()
            ->assertJsonPath('data.is_holiday', true)
            ->assertJsonPath('data.ends_on', '2026-11-08')
            ->assertJsonPath('data.academic_year.name', '2026-27');

        $this->api('POST', 'calendar-events', $staff, ['title' => 'IA-1', 'type' => 'examination', 'starts_on' => '2026-09-10', 'ends_on' => '2026-09-08'])
            ->assertStatus(422)->assertJsonPath('code', 'INVALID_DATES');
        $this->api('POST', 'calendar-events', $staff, ['title' => 'Staff meeting', 'type' => 'event', 'starts_on' => '2026-11-10', 'audience' => 'staff'])->assertCreated();

        $this->api('GET', 'calendar-events?filter[from]=2026-11-01&filter[to]=2026-11-30', $student)->assertOk()->assertJsonCount(2, 'data');
        $this->api('GET', 'calendar-events?filter[from]=2026-11-01&filter[to]=2026-11-30&filter[audience]=students', $student)->assertOk()->assertJsonCount(1, 'data');
        $this->api('GET', 'calendar-events?filter[from]=2026-11-01&filter[to]=2026-11-30&filter[holidays]=1', $student)->assertOk()->assertJsonPath('data.0.title', 'Deepavali');

        $this->api('POST', 'calendar-events', $student, ['title' => 'Party', 'type' => 'event', 'starts_on' => '2026-11-12'])->assertForbidden();
    }
}
