<?php

namespace Database\Seeders\Demo;

use App\Modules\Academics\Models\CourseOffering;
use App\Modules\Assessment\Models\AssessmentGroup;
use App\Modules\Assessment\Models\AssessmentMark;
use App\Modules\Assessment\Models\Assignment;
use App\Modules\Assessment\Models\CourseOutcome;
use App\Modules\Assessment\Services\AssessmentService;
use App\Modules\Institution\Models\Term;
use App\Modules\Students\Models\Student;
use Database\Seeders\Support\CampusSeeder;

/**
 * Odd 2026 internal assessment for the 2025 batches: course outcomes, a scheme (IA tests best 2 of 3
 * worth 30, assignment worth 10), IA1 and IA2 marks, an assignment with submissions (some evaluated),
 * internal marks submitted or verified for some classes, and a pending mark correction.
 */
class AssessmentSeeder extends CampusSeeder
{
    protected function seed(): void
    {
        if (AssessmentGroup::query()->whereHas('offering.term', fn ($q) => $q->where('name', TeachingSeeder::CURRENT_TERM))->exists()) {
            return;
        }

        $assessment = app(AssessmentService::class);
        $term = Term::query()->where('name', TeachingSeeder::CURRENT_TERM)->firstOrFail();
        $offerings = CourseOffering::query()->with(['course.department', 'sections.allocations.employee.user'])->where('term_id', $term->id)
            ->whereHas('sections.section.batch', fn ($q) => $q->where('admission_year', 2025))->orderBy('id')->get();

        foreach ($offerings as $index => $offering) {
            $teacher = $offering->sections->first()?->allocations->first()?->employee?->user;

            if ($teacher === null) {
                continue;
            }

            foreach ([['CO1', 'Explain the core concepts of '.$offering->course->title, 'understand'], ['CO2', 'Apply the techniques of '.$offering->course->title.' to standard problems', 'apply'], ['CO3', 'Analyse and design solutions using '.$offering->course->title, 'analyse']] as [$code, $text, $bloom]) {
                CourseOutcome::query()->create(['course_id' => $offering->course_id, 'code' => $code, 'description' => $text, 'bloom_level' => $bloom]);
            }

            [$ia1, $ia2, $a1, $assignment] = $this->as($teacher, function () use ($assessment, $offering): array {
                $tests = $assessment->saveGroup($offering, ['name' => 'IA tests', 'best_of' => 2, 'weight' => 30]);
                $work = $assessment->saveGroup($offering, ['name' => 'Assignment', 'best_of' => null, 'weight' => 10]);
                $ia1 = $assessment->saveAssessment($tests, ['name' => 'IA1', 'kind' => 'test', 'held_on' => '2026-09-15', 'max_marks' => 40, 'outcome_codes' => ['CO1', 'CO2']]);
                $ia2 = $assessment->saveAssessment($tests, ['name' => 'IA2', 'kind' => 'test', 'held_on' => '2026-10-20', 'max_marks' => 40, 'outcome_codes' => ['CO2', 'CO3']]);
                $assessment->saveAssessment($tests, ['name' => 'IA3', 'kind' => 'test', 'held_on' => '2026-11-24', 'max_marks' => 40, 'outcome_codes' => ['CO3']]);
                $a1 = $assessment->saveAssessment($work, ['name' => 'Assignment 1', 'kind' => 'assignment', 'held_on' => null, 'max_marks' => 10, 'outcome_codes' => ['CO2']]);
                $assignment = Assignment::query()->create([
                    'course_offering_id' => $offering->id, 'assessment_id' => $a1->id, 'title' => 'Assignment 1: problem set on '.$offering->course->title,
                    'instructions' => "Answer all five questions. Show your working.\nSubmit a PDF or type your answers.",
                    'due_at' => now()->subDays(2)->setTime(23, 59), 'max_marks' => 20, 'allow_late' => true,
                ]);

                return [$ia1, $ia2, $a1, $assignment];
            });

            foreach ($offering->sections as $sectionIndex => $class) {
                $classTeacher = $class->allocations->first()?->employee?->user ?? $teacher;
                $roster = $assessment->roster($class);

                $this->as($classTeacher, function () use ($assessment, $class, $roster, $ia1, $ia2, $classTeacher): void {
                    foreach ([$ia1, $ia2] as $test) {
                        $assessment->enterMarks($test, $class, $roster->mapWithKeys(fn (Student $s) => [$s->id => $this->mark($s->id, $test->id)])->all(), $this->fresh($classTeacher));
                    }
                });

                foreach ($roster as $i => $student) {
                    if ($i % 4 === 3) {
                        continue; // not submitted
                    }

                    $submission = $this->as($student->user, fn () => $assessment->submitAssignment($assignment, $student, null, "Answers to Assignment 1.\n1. …\n2. …"));

                    if ($i % 2 === 0) {
                        $this->as($classTeacher, fn () => $assessment->evaluate($submission, (float) (11 + abs(crc32((string) $student->id)) % 10), $i % 3 === 0 ? 'Good work; explain step 3 more clearly.' : null, $this->fresh($classTeacher)));
                    }
                }

                // Every third course: section A submitted; the first such course is also verified by the HOD.
                if ($index % 3 === 0 && $sectionIndex === 0) {
                    $this->as($classTeacher, fn () => $assessment->submit($class, $this->fresh($classTeacher)));

                    if ($index === 0) {
                        $hod = $this->user('hod.'.strtolower($offering->course->department->code).'@'.self::DOMAIN);
                        $verifier = $hod->id === $classTeacher->id ? $this->admin() : $hod;
                        $this->as($verifier, fn () => $assessment->verify($assessment->sheet($class), true, 'Checked against answer scripts.', $this->fresh($verifier)));
                    } elseif ($index === 3) {
                        $mark = AssessmentMark::query()->where('assessment_id', $ia1->id)->whereIn('student_id', $roster->pluck('id'))->whereNotNull('marks')->orderBy('id')->firstOrFail();
                        $this->as($classTeacher, fn () => $assessment->requestCorrection($mark, min(40.0, (float) $mark->marks + 4), 'present', 'Question 6 was not added to the total.', $this->fresh($classTeacher)));
                    }
                }
            }
        }
    }

    /**
     * @return array{marks: float|null, status: string}
     */
    private function mark(int $studentId, int $assessmentId): array
    {
        $roll = abs(crc32($studentId.':'.$assessmentId)) % 100;

        if ($roll < 3) {
            return ['marks' => null, 'status' => 'absent'];
        }

        return ['marks' => (float) (12 + $roll % 29), 'status' => 'present'];
    }
}
