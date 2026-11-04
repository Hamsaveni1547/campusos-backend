<?php

namespace Database\Seeders\Demo;

use App\Modules\HR\Models\Designation;
use App\Modules\Institution\Enums\InstitutionType;
use App\Modules\Institution\Models\AcademicYear;
use App\Modules\Institution\Models\Batch;
use App\Modules\Institution\Models\CalendarEvent;
use App\Modules\Institution\Models\Department;
use App\Modules\Institution\Models\Institution;
use App\Modules\Institution\Models\Programme;
use App\Modules\Institution\Models\Room;
use App\Modules\Institution\Models\Term;
use App\Modules\Institution\Services\AcademicCalendar;
use App\Shared\Settings\SettingsRepository;
use App\Shared\Tenancy\CurrentInstitution;
use Database\Seeders\Support\CampusSeeder;

/**
 * Bangalore Institute of Technology: departments, programmes (BE CS, BE AI&DS, BE IT, M.Tech, MCA, MBA),
 * two academic years with their terms (Odd 2026 current), batches and sections, rooms, calendar and designations.
 */
class InstitutionSetupSeeder extends CampusSeeder
{
    /**
     * Programme code => [department code, name, level, years, intake, sections, admission years].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: int, 4: int, 5: list<string>, 6: list<int>}>
     */
    public const PROGRAMMES = [
        'BE-CS' => ['CSE', 'B.E. Computer Science and Engineering', 'ug', 4, 180, ['A', 'B'], [2022, 2023, 2024, 2025, 2026]],
        'BE-AIDS' => ['AIDS', 'B.E. Artificial Intelligence and Data Science', 'ug', 4, 120, ['A', 'B'], [2023, 2024, 2025, 2026]],
        'BE-IT' => ['ISE', 'B.E. Information Technology', 'ug', 4, 120, ['A', 'B'], [2023, 2024, 2025, 2026]],
        'MTECH-CSE' => ['CSE', 'M.Tech Computer Science and Engineering', 'pg', 2, 18, ['A'], [2025, 2026]],
        'MCA' => ['MCA', 'Master of Computer Applications', 'pg', 2, 120, ['A'], [2025, 2026]],
        'MBA' => ['MBA', 'Master of Business Administration', 'pg', 2, 120, ['A'], [2025, 2026]],
    ];

    public function run(): void
    {
        $institution = Institution::query()->firstOrCreate(['code' => self::INSTITUTION_CODE], [
            'name' => 'Bangalore Institute of Technology',
            'short_name' => 'BIT',
            'type' => InstitutionType::College,
            'email' => 'principal@bit.campusos.test',
            'phone' => '+91 80 2661 5865',
            'website' => 'https://bit-bangalore.edu.in',
            'address_line' => 'K.R. Road, V.V. Puram',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'country' => 'IN',
            'postal_code' => '560004',
            'timezone' => 'Asia/Kolkata',
            'currency' => 'INR',
            'affiliation' => 'Visvesvaraya Technological University (VTU)',
            'established_year' => 1979,
        ]);

        app(CurrentInstitution::class)->runAs($institution, fn () => $this->seed());
    }

    protected function seed(): void
    {
        if (Department::query()->exists()) {
            return;
        }

        app(SettingsRepository::class)->update('security', ['mfa_required_roles' => []], $this->institution()->id);

        $departments = collect([
            ['CSE', 'Computer Science and Engineering', 'academic', 1979],
            ['AIDS', 'Artificial Intelligence and Data Science', 'academic', 2020],
            ['ISE', 'Information Science and Technology', 'academic', 1992],
            ['MCA', 'Master of Computer Applications', 'academic', 1997],
            ['MBA', 'Management Studies', 'academic', 1997],
            ['ADM', 'Administration', 'administrative', 1979],
            ['EXAM', 'Office of the Controller of Examinations', 'administrative', 1979],
            ['LIB', 'Central Library', 'administrative', 1979],
        ])->mapWithKeys(fn (array $d): array => [$d[0] => Department::query()->create([
            'code' => $d[0], 'name' => $d[1], 'type' => $d[2], 'established_year' => $d[3], 'email' => strtolower($d[0]).'@bit.campusos.test',
        ])]);

        foreach (self::PROGRAMMES as $code => [$department, $name, $level, $years, $intake, $sections, $admissionYears]) {
            $programme = Programme::query()->create([
                'department_id' => $departments[$department]->id, 'code' => $code, 'name' => $name, 'level' => $level,
                'duration_years' => $years, 'total_terms' => $years * 2, 'intake_capacity' => $intake, 'regulation' => '2022 Scheme',
            ]);

            foreach ($admissionYears as $year) {
                $batch = Batch::query()->create(['programme_id' => $programme->id, 'admission_year' => $year, 'name' => "{$code} {$year}"]);

                foreach ($sections as $section) {
                    $batch->sections()->create(['name' => $section, 'capacity' => 60]);
                }
            }
        }

        $calendar = app(AcademicCalendar::class);
        $previousYear = AcademicYear::query()->create(['name' => '2025-26', 'starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);
        Term::query()->create(['academic_year_id' => $previousYear->id, 'type' => 'odd', 'name' => 'Odd Semester 2025', 'starts_on' => '2025-08-01', 'ends_on' => '2025-12-31']);
        Term::query()->create(['academic_year_id' => $previousYear->id, 'type' => 'even', 'name' => 'Even Semester 2026', 'starts_on' => '2026-02-01', 'ends_on' => '2026-06-15']);

        $year = AcademicYear::query()->create(['name' => '2026-27', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $odd = Term::query()->create(['academic_year_id' => $year->id, 'type' => 'odd', 'name' => 'Odd Semester 2026', 'starts_on' => '2026-08-01', 'ends_on' => '2026-12-31']);
        Term::query()->create(['academic_year_id' => $year->id, 'type' => 'even', 'name' => 'Even Semester 2027', 'starts_on' => '2027-01-15', 'ends_on' => '2027-06-15']);
        $calendar->makeTermCurrent($odd);

        foreach ([
            ['Kannada Rajyotsava', 'holiday', '2026-11-01', '2026-11-01', true, 'all'],
            ['Deepavali', 'holiday', '2026-11-08', '2026-11-09', true, 'all'],
            ['Internal Assessment 1', 'examination', '2026-09-14', '2026-09-19', false, 'students'],
            ['Internal Assessment 2', 'examination', '2026-10-19', '2026-10-24', false, 'students'],
            ['Internal Assessment 3', 'examination', '2026-11-23', '2026-11-28', false, 'students'],
            ['Semester End Examinations', 'examination', '2026-12-07', '2026-12-24', false, 'students'],
            ['Faculty Development Programme', 'event', '2026-11-16', '2026-11-18', false, 'staff'],
        ] as [$title, $type, $from, $to, $holiday, $audience]) {
            CalendarEvent::query()->create(['academic_year_id' => $year->id, 'title' => $title, 'type' => $type, 'starts_on' => $from, 'ends_on' => $to, 'is_holiday' => $holiday, 'audience' => $audience]);
        }

        foreach ([
            ['LH-101', 'Lecture Hall 101', 'classroom', 72, null, 'Main Block', 1], ['LH-102', 'Lecture Hall 102', 'classroom', 72, null, 'Main Block', 1],
            ['LH-201', 'Lecture Hall 201', 'classroom', 72, null, 'Main Block', 2], ['LH-202', 'Lecture Hall 202', 'classroom', 72, null, 'Main Block', 2],
            ['PG-101', 'PG Block Classroom 1', 'classroom', 60, null, 'PG Block', 1], ['PG-102', 'PG Block Classroom 2', 'classroom', 60, null, 'PG Block', 1],
            ['CS-LAB1', 'Programming Lab 1', 'laboratory', 36, 'CSE', 'CSE Block', 2], ['AI-LAB1', 'AI & ML Lab', 'laboratory', 36, 'AIDS', 'CSE Block', 3],
            ['IS-LAB1', 'Networks Lab', 'laboratory', 36, 'ISE', 'ISE Block', 1], ['MCA-LAB', 'MCA Computing Lab', 'laboratory', 60, 'MCA', 'PG Block', 2],
            ['SEM-HALL', 'Sir M. Visvesvaraya Seminar Hall', 'seminar_hall', 250, null, 'Main Block', 0],
        ] as [$code, $name, $type, $capacity, $department, $building, $floor]) {
            Room::query()->create(['code' => $code, 'name' => $name, 'type' => $type, 'capacity' => $capacity, 'building' => $building, 'floor' => (string) $floor, 'department_id' => $department ? $departments[$department]->id : null]);
        }

        foreach ([
            ['Principal', 'teaching', 1], ['Professor', 'teaching', 10], ['Associate Professor', 'teaching', 20], ['Assistant Professor', 'teaching', 30],
            ['Registrar', 'non_teaching', 15], ['Office Superintendent', 'non_teaching', 40], ['Accounts Officer', 'non_teaching', 40],
            ['Librarian', 'non_teaching', 35], ['Lab Instructor', 'non_teaching', 50], ['First Division Assistant', 'non_teaching', 60],
        ] as [$name, $category, $rank]) {
            Designation::query()->create(['name' => $name, 'category' => $category, 'rank' => $rank]);
        }
    }
}
