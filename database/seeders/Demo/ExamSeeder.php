<?php

namespace Database\Seeders\Demo;

use App\Modules\Academics\Models\CourseOffering;
use App\Modules\Exams\Models\ExamCycle;
use App\Modules\Exams\Services\ExamService;
use App\Modules\Institution\Models\Term;
use App\Modules\Students\Models\Student;
use Database\Seeders\Support\CampusSeeder;
use Illuminate\Support\Carbon;

/**
 * This semester's exams: the December 2026 end-term cycle in draft with its timetable, and a
 * supplementary cycle open for backlog registration (one backlog student already registered;
 * student 2 of BE-CS 2025 A is left to register themselves).
 */
class ExamSeeder extends CampusSeeder
{
    protected function seed(): void
    {
        $current = Term::query()->where('name', TeachingSeeder::CURRENT_TERM)->firstOrFail();

        if (ExamCycle::query()->where('term_id', $current->id)->exists()) {
            return;
        }

        $exams = app(ExamService::class);
        $coe = $this->user('coe@'.self::DOMAIN);
        $past = Term::query()->where('name', TeachingSeeder::PAST_TERM)->firstOrFail();

        $this->as($coe, function () use ($exams, $current, $past): void {
            $cycle = ExamCycle::query()->create(['term_id' => $current->id, 'name' => 'Semester End Examinations December 2026', 'type' => 'regular'])->refresh();
            $day = 0;

            foreach (CourseOffering::query()->with('course')->where('term_id', $current->id)->get()->groupBy(fn (CourseOffering $o) => $o->course->code[strlen($o->course->code) - 1]) as $slot => $offerings) {
                foreach ($offerings as $offering) {
                    $exams->saveSchedule($cycle, [
                        'course_id' => $offering->course_id, 'exam_date' => Carbon::parse('2026-12-07')->addWeekdays($day)->toDateString(),
                        'starts_at' => '09:30', 'ends_at' => '12:30', 'max_marks' => 60,
                    ]);
                }

                $day += 2;
            }

            $supply = ExamCycle::query()->create(['term_id' => $past->id, 'name' => 'Supplementary Examinations November 2026', 'type' => 'supplementary', 'registration_ends_on' => now()->addDays(10)->toDateString()])->refresh();
            $exams->openRegistration($supply);

            $skip = Student::query()->where('user_id', $this->user('student@'.self::DOMAIN)->id)->value('id');

            foreach (Student::query()->orderBy('id')->get() as $student) {
                $backlogs = $exams->backlogs($student);

                if ($backlogs->isNotEmpty() && $student->id !== $skip + 1) {
                    $exams->registerBacklogs($supply, $student, [$backlogs->first()->course_id]);
                    break;
                }
            }
        });
    }
}
