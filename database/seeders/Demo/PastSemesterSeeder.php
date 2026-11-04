<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use App\Modules\Academics\Models\CourseOffering;
use App\Modules\Academics\Models\OfferingSection;
use App\Modules\Academics\Services\RegistrationService;
use App\Modules\Assessment\Services\AssessmentService;
use App\Modules\Exams\Models\ExamCycle;
use App\Modules\Exams\Models\ExamRegistration;
use App\Modules\Exams\Services\ExamService;
use App\Modules\Institution\Models\Term;
use App\Modules\Institution\Services\AcademicCalendar;
use App\Modules\Students\Models\Student;
use App\Shared\Exceptions\BusinessRuleException;
use Database\Seeders\Support\CampusSeeder;
use Illuminate\Support\Carbon;

/**
 * Last semester (Even 2026) run end to end, so results, CGPA, backlogs and revaluation have history:
 * registration → internal marks (submitted, verified, locked) → exam cycle → published results.
 *
 * Marks are deterministic. Some scripts fail the exam minimum or are absent, which creates backlogs
 * (the second student of BE-CS 2025 A always has one, for the supplementary exam).
 */
class PastSemesterSeeder extends CampusSeeder
{
    protected function seed(): void
    {
        if (ExamCycle::query()->exists()) {
            return;
        }

        $calendar = app(AcademicCalendar::class);
        $past = Term::query()->where('name', TeachingSeeder::PAST_TERM)->firstOrFail();
        $current = Term::query()->where('name', TeachingSeeder::CURRENT_TERM)->firstOrFail();
        $students = Student::query()->whereHas('batch', fn ($q) => $q->where('admission_year', '<=', 2025)->where('admission_year', '>=', 2023))->get();

        $calendar->makeTermCurrent($past);
        $students->each(fn (Student $s) => $s->forceFill(['current_term_number' => $s->current_term_number - 1])->save());

        try {
            $this->register($past, $students);
            $this->internalMarks($past);
            $this->exam($past);
        } finally {
            $students->each(fn (Student $s) => $s->forceFill(['current_term_number' => $s->current_term_number + 1])->save());
            $calendar->makeTermCurrent($current->refresh());
            $calendar->closeTerm($past);
            $calendar->closeTerm(Term::query()->where('name', 'Odd Semester 2025')->firstOrFail());
        }
    }

    /**
     * @param  iterable<Student>  $students
     */
    private function register(Term $term, iterable $students): void
    {
        $registrations = app(RegistrationService::class);
        $office = $this->user('office@'.self::DOMAIN);

        foreach ($students as $student) {
            try {
                $registration = $this->as($student->user, fn () => $registrations->register($student->refresh(), $term->refresh(), []));
                $this->as($office, fn () => $registrations->decide($registration, true, null, $this->fresh($office)));
            } catch (BusinessRuleException) {
                // Students who are not enrolled (graduated, dropped out) do not register.
            }
        }
    }

    /**
     * Two internal tests worth 40 per course; the teacher submits, the HOD verifies, the office locks.
     */
    private function internalMarks(Term $term): void
    {
        $assessment = app(AssessmentService::class);
        $office = $this->user('office@'.self::DOMAIN);

        foreach (CourseOffering::query()->with(['course.department', 'sections.allocations.employee.user'])->where('term_id', $term->id)->get() as $offering) {
            $teacher = $offering->sections->first()?->allocations->first()?->employee?->user;
            $hod = $this->user('hod.'.strtolower($offering->course->department->code).'@'.self::DOMAIN);

            if ($teacher === null) {
                continue;
            }

            $tests = $this->as($teacher, function () use ($assessment, $offering): array {
                $group = $assessment->saveGroup($offering, ['name' => 'Internal tests', 'best_of' => null, 'weight' => 40]);

                return [
                    $assessment->saveAssessment($group, ['name' => 'IA1', 'kind' => 'test', 'held_on' => '2026-03-16', 'max_marks' => 40]),
                    $assessment->saveAssessment($group, ['name' => 'IA2', 'kind' => 'test', 'held_on' => '2026-04-20', 'max_marks' => 40]),
                ];
            });

            foreach ($offering->sections as $class) {
                /** @var OfferingSection $class */
                $classTeacher = $class->allocations->first()?->employee->user ?? $teacher;
                $roster = $assessment->roster($class);

                $this->as($classTeacher, function () use ($assessment, $tests, $class, $roster, $classTeacher): void {
                    foreach ($tests as $i => $test) {
                        $assessment->enterMarks($test, $class, $roster->mapWithKeys(fn (Student $s) => [$s->id => ['marks' => (float) (18 + $this->score($s->id, $test->id) % 22), 'status' => 'present']])->all(), $this->fresh($classTeacher));
                    }

                    $assessment->submit($class, $this->fresh($classTeacher));
                });

                $sheet = $assessment->sheet($class);
                $verifier = $classTeacher->id === $hod->id ? $this->admin() : $hod;
                $this->as($verifier, fn () => $assessment->verify($sheet, true, null, $this->fresh($verifier)));
                $this->as($office, fn () => $assessment->lock($sheet->refresh(), $this->fresh($office)));
            }
        }
    }

    private function exam(Term $term): void
    {
        $exams = app(ExamService::class);
        $coe = $this->user('coe@'.self::DOMAIN);
        $principal = $this->user('principal@'.self::DOMAIN);

        $this->as($coe, function () use ($exams, $term, $coe, $principal): void {
            $cycle = ExamCycle::query()->create(['term_id' => $term->id, 'name' => 'Semester End Examinations June 2026', 'type' => 'regular'])->refresh();
            $exams->openRegistration($cycle);
            $exams->closeRegistration($cycle->refresh());

            $courses = $cycle->registrations()->with('course')->get()->groupBy('course_id');
            $day = 0;

            foreach ($courses as $courseId => $rows) {
                $schedule = $exams->saveSchedule($cycle, ['course_id' => $courseId, 'exam_date' => Carbon::parse('2026-06-01')->addWeekdays($day++ % 12)->toDateString(), 'starts_at' => $day % 2 ? '09:30' : '14:00', 'ends_at' => $day % 2 ? '12:30' : '17:00', 'max_marks' => 60]);
                $entries = [];

                foreach ($rows->where('eligibility', 'eligible') as $row) {
                    /** @var ExamRegistration $row */
                    $entries[$row->script_code] = $this->externalMarks($row);
                }

                $exams->enterMarks($schedule, $entries, $this->fresh($coe));
            }

            $exams->process($cycle->refresh(), $this->fresh($coe));
            $this->as($principal, fn () => $exams->verify($cycle->refresh(), $this->fresh($principal)));
            $exams->publish($cycle->refresh(), $this->fresh($coe));

            $cycle->forceFill(['processed_at' => '2026-07-05 11:00:00', 'verified_at' => '2026-07-07 16:00:00', 'published_at' => '2026-07-08 10:00:00'])->save();
            $this->revaluations($exams, $coe);
        });
    }

    /**
     * @return array{attendance: string, marks: float|null}
     */
    private function externalMarks(ExamRegistration $row): array
    {
        $backlogStudent = Student::query()->whereHas('programme', fn ($q) => $q->where('code', 'BE-CS'))->whereHas('batch', fn ($q) => $q->where('admission_year', 2025))->orderBy('id')->skip(1)->value('id');
        $score = $this->score($row->student_id, $row->course_id);

        if ($score % 47 === 0) {
            return ['attendance' => 'absent', 'marks' => null];
        }

        if (($row->student_id === $backlogStudent && $row->course->code === '22CS22') || $score % 17 === 0) {
            return ['attendance' => 'present', 'marks' => (float) (8 + $score % 12)];
        }

        return ['attendance' => 'present', 'marks' => (float) (24 + $score % 35)];
    }

    private function revaluations(ExamService $exams, User $coe): void
    {
        $student = $this->user('student@'.self::DOMAIN);
        $rows = ExamRegistration::query()->whereHas('student', fn ($q) => $q->where('user_id', $student->id))->where('attendance', 'present')->orderBy('id')->get();

        if ($rows->count() >= 2) {
            $done = $this->as($student, fn () => $exams->requestRevaluation($rows[0], 'I expected higher marks in Part B.', $this->fresh($student)));
            $exams->decideRevaluation($done->refresh(), min(60.0, (float) $rows[0]->external_marks + 6), 'Totalling error corrected in question 7.', $this->fresh($coe));
            $this->as($student, fn () => $exams->requestRevaluation($rows[1], 'Please recheck question 4.', $this->fresh($student)));
        }
    }

    private function score(int $a, int $b): int
    {
        return abs(crc32($a.'-'.$b)) % 1000;
    }
}
