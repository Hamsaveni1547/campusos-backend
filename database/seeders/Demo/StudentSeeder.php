<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use App\Modules\Institution\Models\Batch;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Services\StudentService;
use Database\Seeders\Support\CampusSeeder;
use Illuminate\Support\Carbon;

/**
 * Students in every batch and section with guardians and varied profiles, plus status changes
 * (on leave, detained, suspended, year back, dropped out, graduated) and pending profile corrections.
 *
 * The first student of BE-CS 2025 section A signs in as student@bit.campusos.test.
 */
class StudentSeeder extends CampusSeeder
{
    public const PER_SECTION = ['ug' => 6, 'pg' => 8];

    private const FIRST = ['Aarav', 'Ananya', 'Vihaan', 'Diya', 'Aditya', 'Ishita', 'Rohan', 'Kavya', 'Arjun', 'Sneha', 'Karthik', 'Meera', 'Nikhil', 'Pooja', 'Rahul', 'Shreya', 'Siddharth', 'Tanvi', 'Varun', 'Yashaswini', 'Abhishek', 'Bhoomika', 'Chirag', 'Deepika', 'Gagan', 'Harini', 'Imran', 'Jahnavi', 'Kiran', 'Lavanya', 'Manoj', 'Nithya', 'Pranav', 'Rakshitha', 'Sachin', 'Spoorthi', 'Tejas', 'Usha', 'Vivek', 'Zoya'];

    private const LAST = ['Sharma', 'Rao', 'Gowda', 'Hegde', 'Iyer', 'Kulkarni', 'Nayak', 'Reddy', 'Shetty', 'Bhat', 'Patil', 'Menon', 'Naidu', 'Joshi', 'Prasad', 'Murthy', 'Khan', 'Pai', 'Desai', 'Kamath'];

    protected function seed(): void
    {
        if (Student::query()->exists()) {
            return;
        }

        $service = app(StudentService::class);
        $office = $this->user('office@'.self::DOMAIN);
        $sequence = 0;

        $this->as($office, function () use ($service, $office, &$sequence): void {
            foreach (Batch::query()->with(['programme', 'sections'])->orderBy('programme_id')->orderBy('admission_year')->get() as $batch) {
                $programme = $batch->programme;
                $level = $programme->level->value === 'pg' ? 'pg' : 'ug';
                $term = min((2026 - $batch->admission_year) * 2 + 1, $programme->total_terms);
                $count = $batch->admission_year === 2022 ? 4 : self::PER_SECTION[$level];

                foreach ($batch->sections as $section) {
                    foreach (range(1, $count) as $roll) {
                        $n = $sequence++;
                        $first = self::FIRST[$n % count(self::FIRST)];
                        $last = self::LAST[($n * 7 + intdiv($n, count(self::FIRST))) % count(self::LAST)];
                        $email = $programme->code === 'BE-CS' && $batch->admission_year === 2025 && $section->name === 'A' && $roll === 1
                            ? 'student@'.self::DOMAIN
                            : strtolower($first.'.'.$last.'.'.$n).'@students.'.self::DOMAIN;
                        $female = in_array($first, ['Ananya', 'Diya', 'Ishita', 'Kavya', 'Sneha', 'Meera', 'Pooja', 'Shreya', 'Tanvi', 'Yashaswini', 'Bhoomika', 'Deepika', 'Harini', 'Jahnavi', 'Lavanya', 'Nithya', 'Rakshitha', 'Spoorthi', 'Usha', 'Zoya'], true);

                        $student = $service->create(
                            ['name' => $first.' '.$last, 'email' => $email, 'mobile' => '+919'.str_pad((string) (800000000 + $n * 7919 % 99999999), 9, '0', STR_PAD_LEFT)],
                            [
                                'programme_id' => $programme->id, 'batch_id' => $batch->id, 'section_id' => $section->id,
                                'roll_no' => sprintf('%02d', $roll), 'admission_type' => $n % 9 === 0 ? 'management' : ($level === 'pg' && $n % 5 === 0 ? 'lateral' : 'cet'),
                                'category' => ['GM', 'GM', '2A', '3B', 'SC', 'EWS', 'CAT1', '3A'][$n % 8],
                                'admitted_on' => $batch->admission_year.'-08-0'.(1 + $n % 5), 'current_term_number' => $term,
                                'date_of_birth' => Carbon::create($batch->admission_year - ($level === 'pg' ? 22 : 18), 1 + $n % 12, 1 + $n % 27)->toDateString(),
                                'gender' => $female ? 'female' : 'male', 'blood_group' => ['O+', 'B+', 'A+', 'AB+', 'O-', 'B-'][$n % 6], 'nationality' => 'Indian',
                                'mother_tongue' => ['Kannada', 'Telugu', 'Tamil', 'Hindi', 'Malayalam'][$n % 5],
                                'address' => (10 + $n).', '.['4th Cross, Jayanagar', 'Basavanagudi', '8th Main, Malleshwaram', 'Rajajinagar', 'BTM Layout'][$n % 5].', Bengaluru 5600'.str_pad((string) (4 + $n % 90), 2, '0', STR_PAD_LEFT),
                            ],
                            $office,
                            sendInvite: false,
                            guardians: [
                                ['relation' => 'father', 'name' => self::FIRST[($n + 11) % count(self::FIRST)].' '.$last, 'phone' => '+9194480'.str_pad((string) ($n * 37 % 99999), 5, '0', STR_PAD_LEFT), 'occupation' => ['Engineer', 'Farmer', 'Teacher', 'Business', 'Government service'][$n % 5], 'annual_income' => 300000 + ($n % 10) * 150000, 'is_primary' => true],
                                ['relation' => 'mother', 'name' => self::FIRST[($n + 3) % count(self::FIRST)].' '.$last, 'phone' => '+9197410'.str_pad((string) ($n * 53 % 99999), 5, '0', STR_PAD_LEFT), 'occupation' => ['Homemaker', 'Doctor', 'Teacher', 'Banker'][$n % 4], 'is_primary' => false],
                            ],
                        );
                        $this->activate($student->user);
                    }
                }
            }

            $this->statusChanges($service, $office);
        });

        $this->command?->info('Students seeded: '.Student::query()->count().'. Student sign-in: student@'.self::DOMAIN.' / '.self::DEMO_PASSWORD.'.');
    }

    private function statusChanges(StudentService $service, User $office): void
    {
        $pick = fn (string $programme, int $year, int $offset): Student => Student::query()
            ->whereHas('programme', fn ($q) => $q->where('code', $programme))
            ->whereHas('batch', fn ($q) => $q->where('admission_year', $year))
            ->orderBy('id')->skip($offset)->firstOrFail();

        foreach (Student::query()->whereHas('batch', fn ($q) => $q->where('admission_year', 2022))->get() as $graduate) {
            $service->changeStatus($graduate, StudentStatus::Graduated, Carbon::parse('2026-07-31'), 'Completed the programme', $office);
        }

        foreach ([
            ['BE-CS', 2024, 5, StudentStatus::OnLeave, 'Medical leave for one semester'],
            ['BE-AIDS', 2025, 4, StudentStatus::Detained, 'Attendance below the minimum in the previous semester'],
            ['BE-IT', 2024, 3, StudentStatus::Suspended, 'Disciplinary committee decision'],
            ['BE-IT', 2023, 2, StudentStatus::YearBack, 'Did not clear the required credits'],
            ['MBA', 2025, 6, StudentStatus::DroppedOut, 'Left for employment'],
            ['MCA', 2026, 7, StudentStatus::DroppedOut, 'Withdrew admission'],
        ] as [$programme, $year, $offset, $status, $reason]) {
            $service->changeStatus($pick($programme, $year, $offset), $status, now()->subWeeks(4), $reason, $office);
        }

        $service->requestChange($pick('BE-CS', 2025, 3), ['date_of_birth' => '2007-03-14'], 'Date of birth is wrong in the record; SSLC marks card attached at the office.');
        $service->requestChange($pick('MCA', 2025, 2), ['category' => 'EWS'], 'EWS certificate issued after admission; please update my category.');
    }
}
