<?php

namespace Tests\Feature\Timetable;

use App\Models\User;
use App\Modules\Identity\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TimetableTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sections A and B; DBMS offered to both (Kavya teaches both); AI offered to A (Ravi); room B-101;
     * periods P1 09–10, P2 10–11, break, P3 11:15–12:15, Monday to Saturday.
     *
     * @return array<string, mixed>
     */
    private function campus(): array
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);

        $department = $this->api('POST', 'departments', $admin, ['code' => 'CSE', 'name' => 'Computer Science'])->json('data');
        $programme = $this->api('POST', 'programmes', $admin, ['department_id' => $department['id'], 'code' => 'BE-CSE', 'name' => 'B.E. CSE', 'level' => 'ug', 'duration_years' => 4])->json('data');
        $batch = $this->api('POST', 'batches', $admin, ['programme_id' => $programme['id'], 'admission_year' => 2026, 'sections' => ['A', 'B']])->json('data');
        [$sectionA, $sectionB] = array_column($batch['sections'], 'id');
        $year = $this->api('POST', 'academic-years', $admin, ['name' => '2026-27', 'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonths(11)->toDateString()])->json('data');
        $term = $this->api('POST', 'terms', $admin, ['academic_year_id' => $year['id'], 'type' => 'odd', 'name' => 'Odd 2026', 'starts_on' => now()->subWeeks(2)->toDateString(), 'ends_on' => now()->addMonths(4)->toDateString()])->json('data');
        $this->api('POST', 'terms/'.$term['id'].'/make-current', $admin);
        $room = $this->api('POST', 'rooms', $admin, ['code' => 'B-101', 'type' => 'classroom', 'capacity' => 60])->json('data');

        $dbms = $this->api('POST', 'courses', $admin, ['department_id' => $department['id'], 'code' => '21CS51', 'title' => 'DBMS', 'type' => 'theory', 'credits' => 4])->json('data');
        $ai = $this->api('POST', 'courses', $admin, ['department_id' => $department['id'], 'code' => '21CS52', 'title' => 'AI', 'type' => 'theory', 'credits' => 3])->json('data');
        $dbmsOffering = $this->api('POST', 'course-offerings', $admin, ['course_id' => $dbms['id'], 'term_id' => $term['id'], 'section_ids' => [$sectionA, $sectionB]])->json('data');
        $aiOffering = $this->api('POST', 'course-offerings', $admin, ['course_id' => $ai['id'], 'term_id' => $term['id'], 'section_ids' => [$sectionA]])->json('data');

        $kavya = $this->api('POST', 'employees', $admin, ['name' => 'Dr. Kavya', 'email' => 'kavya@example.edu', 'staff_type' => 'teaching', 'employment_type' => 'permanent', 'joined_on' => '2020-06-01', 'send_invite' => false])->json('data');
        $ravi = $this->api('POST', 'employees', $admin, ['name' => 'Dr. Ravi', 'email' => 'ravi@example.edu', 'staff_type' => 'teaching', 'employment_type' => 'permanent', 'joined_on' => '2020-06-01', 'send_invite' => false])->json('data');

        $classes = [
            'dbmsA' => collect($dbmsOffering['sections'])->firstWhere('section.id', $sectionA)['id'],
            'dbmsB' => collect($dbmsOffering['sections'])->firstWhere('section.id', $sectionB)['id'],
            'aiA' => $aiOffering['sections'][0]['id'],
        ];
        $this->api('POST', 'offering-sections/'.$classes['dbmsA'].'/allocations', $admin, ['employee_id' => $kavya['id'], 'component' => 'theory', 'weekly_hours' => 4]);
        $this->api('POST', 'offering-sections/'.$classes['dbmsB'].'/allocations', $admin, ['employee_id' => $kavya['id'], 'component' => 'theory', 'weekly_hours' => 4]);
        $this->api('POST', 'offering-sections/'.$classes['aiA'].'/allocations', $admin, ['employee_id' => $ravi['id'], 'component' => 'theory', 'weekly_hours' => 3]);

        $template = $this->api('POST', 'slot-templates', $admin, ['name' => 'Regular day', 'working_days' => [1, 2, 3, 4, 5, 6], 'is_default' => true, 'slots' => [
            ['label' => 'P1', 'starts_at' => '09:00', 'ends_at' => '10:00'],
            ['label' => 'P2', 'starts_at' => '10:00', 'ends_at' => '11:00'],
            ['label' => 'Break', 'starts_at' => '11:00', 'ends_at' => '11:15', 'is_break' => true],
            ['label' => 'P3', 'starts_at' => '11:15', 'ends_at' => '12:15'],
        ]])->assertCreated()->json('data');
        $slots = collect($template['slots'])->pluck('id', 'label')->all();

        $ttA = $this->api('POST', 'timetables', $admin, ['term_id' => $term['id'], 'section_id' => $sectionA, 'slot_template_id' => $template['id']])->assertCreated()->json('data');
        $ttB = $this->api('POST', 'timetables', $admin, ['term_id' => $term['id'], 'section_id' => $sectionB, 'slot_template_id' => $template['id']])->json('data');

        return compact('institution', 'admin', 'programme', 'batch', 'sectionA', 'sectionB', 'term', 'room', 'kavya', 'ravi', 'classes', 'template', 'slots', 'ttA', 'ttB');
    }

    private function activate(string $email): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        $user->forceFill(['password' => Hash::make(UserFactory::PASSWORD), 'status' => UserStatus::Active, 'email_verified_at' => now(), 'must_change_password' => false])->save();

        return $user;
    }

    public function test_builder_rejects_clashes_and_publishing_shows_the_week(): void
    {
        ['admin' => $admin, 'room' => $room, 'kavya' => $kavya, 'ravi' => $ravi, 'classes' => $classes, 'slots' => $slots, 'ttA' => $ttA, 'ttB' => $ttB, 'template' => $template] = $this->campus();
        $entry = fn (array $data) => array_merge(['day_of_week' => 1, 'time_slot_id' => $slots['P1']], $data);

        $this->api('POST', 'slot-templates', $admin, ['name' => 'Bad', 'working_days' => [1], 'slots' => [['label' => 'P1', 'starts_at' => '10:00', 'ends_at' => '09:00']]])->assertStatus(422)->assertJsonPath('code', 'SLOTS_INVALID');

        $this->api('POST', 'timetables/'.$ttA['id'].'/entries', $admin, $entry(['offering_section_id' => $classes['dbmsA'], 'room_id' => $room['id']]))->assertOk()
            ->assertJsonPath('data.entries.0.teacher.name', 'Dr. Kavya')
            ->assertJsonPath('data.planned_hours.0.planned', 1)->assertJsonPath('data.planned_hours.0.allocated', 4);
        $this->api('POST', 'timetables/'.$ttA['id'].'/entries', $admin, $entry(['offering_section_id' => $classes['aiA']]))->assertStatus(409)->assertJsonPath('code', 'SECTION_BUSY');
        $this->api('POST', 'timetables/'.$ttA['id'].'/entries', $admin, $entry(['time_slot_id' => $slots['Break'], 'offering_section_id' => $classes['aiA']]))->assertStatus(422)->assertJsonPath('code', 'SLOT_INVALID');
        $this->api('POST', 'timetables/'.$ttA['id'].'/entries', $admin, $entry(['day_of_week' => 7, 'offering_section_id' => $classes['aiA']]))->assertStatus(422)->assertJsonPath('code', 'DAY_INVALID');
        $this->api('POST', 'timetables/'.$ttA['id'].'/entries', $admin, $entry(['time_slot_id' => $slots['P2'], 'offering_section_id' => $classes['aiA'], 'employee_id' => $kavya['id']]))->assertStatus(422)->assertJsonPath('code', 'TEACHER_NOT_ALLOCATED');
        $this->api('POST', 'timetables/'.$ttA['id'].'/entries', $admin, $entry(['time_slot_id' => $slots['P2'], 'offering_section_id' => $classes['aiA']]))->assertOk();
        $this->api('POST', 'timetables/'.$ttA['id'].'/entries', $admin, $entry(['offering_section_id' => $classes['dbmsB']]))->assertStatus(422)->assertJsonPath('code', 'CLASS_INVALID');

        $this->api('POST', 'timetables/'.$ttB['id'].'/entries', $admin, $entry(['offering_section_id' => $classes['dbmsB']]))->assertStatus(409)->assertJsonPath('code', 'TEACHER_BUSY');
        $this->api('POST', 'timetables/'.$ttB['id'].'/entries', $admin, $entry(['time_slot_id' => $slots['P2'], 'offering_section_id' => $classes['dbmsB'], 'room_id' => $room['id']]))->assertOk();
        $this->api('POST', 'timetables/'.$ttB['id'].'/entries', $admin, $entry(['time_slot_id' => $slots['P3'], 'offering_section_id' => $classes['dbmsB'], 'room_id' => $room['id']]))->assertOk();
        $bP3 = $this->api('GET', 'timetables/'.$ttB['id'], $admin)->json('data.entries.1.id');
        $this->api('PATCH', 'timetable-entries/'.$bP3, $admin, $entry(['offering_section_id' => $classes['dbmsB'], 'room_id' => $room['id']]))->assertStatus(409);

        $this->api('PATCH', 'slot-templates/'.$template['id'], $admin, ['slots' => [['label' => 'P1', 'starts_at' => '09:00', 'ends_at' => '10:00']]])->assertStatus(409)->assertJsonPath('code', 'TEMPLATE_IN_USE');

        $this->api('POST', 'timetables/'.$ttA['id'].'/publish', $admin)->assertOk()->assertJsonPath('data.status', 'published')->assertJsonPath('data.clashes', []);
        $this->api('POST', 'timetables/'.$ttA['id'].'/entries', $admin, $entry(['time_slot_id' => $slots['P3'], 'offering_section_id' => $classes['dbmsA']]))->assertStatus(409)->assertJsonPath('code', 'TIMETABLE_PUBLISHED');
        $this->api('POST', 'timetables/'.$ttB['id'].'/publish', $admin)->assertOk();

        $this->api('GET', 'timetable-view/week?filter[teacher]='.$kavya['id'], $admin)->assertOk()->assertJsonCount(3, 'data.entries')->assertJsonPath('data.entries.0.starts_at', '09:00');
        $this->api('GET', 'timetable-view/week?filter[room]='.$room['id'], $admin)->assertOk()->assertJsonCount(3, 'data.entries');
        $this->api('GET', 'timetable-view/week?filter[teacher]='.$ravi['id'], $admin)->assertJsonPath('data.entries.0.course.code', '21CS52');
        $this->api('GET', 'timetables', $admin)->assertOk()->assertJsonCount(2, 'data');

        $this->api('POST', 'timetables/'.$ttA['id'].'/unpublish', $admin)->assertOk()->assertJsonPath('data.status', 'draft');
        $this->api('POST', 'timetables/'.$ttA['id'].'/publish', $admin)->assertOk()->assertJsonPath('data.version', 2);
    }

    public function test_daily_changes_and_personal_timetables(): void
    {
        ['admin' => $admin, 'programme' => $programme, 'batch' => $batch, 'sectionA' => $sectionA, 'kavya' => $kavya, 'ravi' => $ravi, 'classes' => $classes, 'slots' => $slots, 'ttA' => $ttA, 'ttB' => $ttB] = $this->campus();
        $this->api('POST', 'timetables/'.$ttA['id'].'/entries', $admin, ['day_of_week' => 1, 'time_slot_id' => $slots['P1'], 'offering_section_id' => $classes['dbmsA']])->assertOk();
        $this->api('POST', 'timetables/'.$ttA['id'].'/entries', $admin, ['day_of_week' => 1, 'time_slot_id' => $slots['P2'], 'offering_section_id' => $classes['aiA']])->assertOk();
        $this->api('POST', 'timetables/'.$ttB['id'].'/entries', $admin, ['day_of_week' => 1, 'time_slot_id' => $slots['P2'], 'offering_section_id' => $classes['dbmsB']])->assertOk();
        $entries = collect($this->api('GET', 'timetables/'.$ttA['id'], $admin)->json('data.entries'))->keyBy('course.code');
        $bEntry = $this->api('GET', 'timetables/'.$ttB['id'], $admin)->json('data.entries.0.id');

        $monday = Carbon::now()->next(Carbon::MONDAY);
        $this->api('POST', 'timetable-adjustments', $admin, ['entry_id' => $entries['21CS51']['id'], 'date' => $monday->toDateString(), 'type' => 'cancelled'])->assertStatus(409)->assertJsonPath('code', 'TIMETABLE_NOT_PUBLISHED');
        $this->api('POST', 'timetables/'.$ttA['id'].'/publish', $admin)->assertOk();
        $this->api('POST', 'timetables/'.$ttB['id'].'/publish', $admin)->assertOk();

        $this->api('POST', 'timetable-adjustments', $admin, ['entry_id' => $entries['21CS51']['id'], 'date' => $monday->copy()->addDay()->toDateString(), 'type' => 'cancelled'])->assertStatus(422)->assertJsonPath('code', 'DATE_INVALID');
        $this->api('POST', 'timetable-adjustments', $admin, ['entry_id' => $entries['21CS51']['id'], 'date' => $monday->toDateString(), 'type' => 'cancelled', 'reason' => 'Faculty meeting'])->assertCreated();
        $this->api('POST', 'timetable-adjustments', $admin, ['entry_id' => $entries['21CS51']['id'], 'date' => $monday->toDateString(), 'type' => 'cancelled'])->assertStatus(409)->assertJsonPath('code', 'ALREADY_ADJUSTED');

        // Ravi teaches AI in A at P2, so he cannot take B's DBMS at P2.
        $this->api('POST', 'timetable-adjustments', $admin, ['entry_id' => $bEntry, 'date' => $monday->toDateString(), 'type' => 'substituted', 'substitute_employee_id' => $ravi['id']])->assertStatus(409)->assertJsonPath('code', 'TEACHER_BUSY');
        $this->api('POST', 'timetable-adjustments', $admin, ['entry_id' => $entries['21CS52']['id'], 'date' => $monday->toDateString(), 'type' => 'substituted', 'substitute_employee_id' => $kavya['id']])->assertStatus(409);
        $this->api('POST', 'timetable-adjustments/extra', $admin, ['timetable_id' => $ttA['id'], 'date' => $monday->toDateString(), 'time_slot_id' => $slots['P1'], 'offering_section_id' => $classes['aiA'], 'employee_id' => $ravi['id'], 'reason' => 'Make-up class'])->assertCreated();
        $this->api('POST', 'timetable-adjustments/extra', $admin, ['timetable_id' => $ttA['id'], 'date' => $monday->toDateString(), 'time_slot_id' => $slots['P1'], 'offering_section_id' => $classes['aiA']])->assertStatus(409)->assertJsonPath('code', 'SECTION_BUSY');

        $day = $this->api('GET', 'timetable-view/day?filter[section]='.$sectionA.'&date='.$monday->toDateString(), $admin)->assertOk()->json('data.periods');
        $this->assertSame(['cancelled', 'extra', 'scheduled'], collect($day)->pluck('status')->all());

        $this->api('POST', 'students', $admin, ['name' => 'Asha', 'email' => 'asha@example.edu', 'programme_id' => $programme['id'], 'admission_type' => 'cet', 'admitted_on' => now()->toDateString(), 'batch_id' => $batch['id'], 'section_id' => $sectionA, 'send_invite' => false])->assertCreated();
        $this->api('GET', 'me/timetable?date='.$monday->toDateString(), $this->activate('asha@example.edu'))->assertOk()
            ->assertJsonPath('data.as', 'student')->assertJsonCount(3, 'data.today')->assertJsonCount(2, 'data.week.entries');
        $this->api('GET', 'me/timetable?date='.$monday->toDateString(), $this->activate('ravi@example.edu'))->assertOk()
            ->assertJsonPath('data.as', 'teacher')->assertJsonCount(2, 'data.today')->assertJsonPath('data.today.0.status', 'extra');

        $this->api('GET', 'timetable-adjustments?filter[from]='.$monday->toDateString().'&filter[to]='.$monday->toDateString(), $admin)->assertOk()->assertJsonCount(2, 'data');
        $faculty = $this->activate('kavya@example.edu');
        $this->api('POST', 'timetable-adjustments', $faculty, ['entry_id' => $bEntry, 'date' => $monday->toDateString(), 'type' => 'cancelled'])->assertForbidden();
        $this->api('GET', 'timetable-view/week?filter[section]='.$sectionA, $faculty)->assertOk();
    }
}
