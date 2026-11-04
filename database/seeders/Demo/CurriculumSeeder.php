<?php

namespace Database\Seeders\Demo;

use App\Modules\Academics\Models\Course;
use App\Modules\Academics\Models\Curriculum;
use App\Modules\Academics\Services\AcademicsService;
use App\Modules\Institution\Models\Programme;
use Database\Seeders\Demo\Data\CourseCatalogue;
use Database\Seeders\Support\CampusSeeder;

/**
 * Courses with syllabus (units and topics) and an approved 2022-scheme curriculum per programme.
 * BE-CS also has a draft 2025-scheme revision waiting for approval.
 */
class CurriculumSeeder extends CampusSeeder
{
    protected function seed(): void
    {
        if (Course::query()->exists()) {
            return;
        }

        $academics = app(AcademicsService::class);
        $principal = $this->user('principal@'.self::DOMAIN);

        foreach (CourseCatalogue::all() as $programmeCode => $terms) {
            $programme = Programme::query()->where('code', $programmeCode)->firstOrFail();
            $hod = $this->user('hod.'.strtolower($programme->department->code).'@'.self::DOMAIN);
            $entries = [];

            $this->as($hod, function () use ($terms, $programme, $academics, &$entries): void {
                foreach ($terms as $term => $courses) {
                    foreach ($courses as [$code, $title, $type, $credits]) {
                        $course = Course::query()->create([
                            'department_id' => $programme->department_id, 'code' => $code, 'title' => $title, 'type' => $type, 'credits' => $credits,
                            'lecture_hours' => in_array($type, ['project', 'internship'], true) ? 0 : 3,
                            'tutorial_hours' => $credits >= 4 && $type === 'theory' ? 1 : 0,
                            'practical_hours' => $type === 'theory_lab' ? 2 : ($type === 'project' ? 4 : 0),
                            'regulation' => '2022 Scheme',
                            'description' => $title.' for '.$programme->name.', semester '.$term.'.',
                        ]);

                        if (! in_array($type, ['project', 'internship'], true)) {
                            $academics->replaceSyllabus($course, $this->syllabus($title));
                        }

                        $entries[] = ['course_id' => $course->id, 'term_number' => $term, 'category' => $type === 'project' ? 'project' : 'core'];
                    }
                }

                $curriculum = $academics->createCurriculum($programme->id, '2022 Scheme');
                $academics->replaceEntries($curriculum, $entries);
                $academics->submitCurriculum($curriculum);
            });

            $this->as($principal, fn () => $academics->decideCurriculum(Curriculum::query()->where('programme_id', $programme->id)->firstOrFail(), true, 'Approved by the Board of Studies', $this->fresh($principal)));
        }

        // A revision in progress, to show the draft → approval flow.
        $beCs = Programme::query()->where('code', 'BE-CS')->firstOrFail();
        $this->as($this->user('hod.cse@'.self::DOMAIN), function () use ($academics, $beCs): void {
            $academics->submitCurriculum($academics->createCurriculum($beCs->id, '2025 Scheme', Curriculum::query()->where('programme_id', $beCs->id)->firstOrFail()));
        });
    }

    /**
     * Five units with three topics each.
     *
     * @return list<array{title: string, hours: int, topics: list<array{title: string, hours: int}>}>
     */
    private function syllabus(string $course): array
    {
        $parts = ['Foundations and terminology', 'Core techniques', 'Design and analysis', 'Applications and case studies', 'Advanced topics and trends'];

        return array_map(fn (int $n): array => [
            'title' => $parts[$n - 1].' of '.$course,
            'hours' => 8,
            'topics' => array_map(fn (int $t): array => ['title' => 'Module '.$n.'.'.$t.': '.['Concepts', 'Methods', 'Practice'][$t - 1], 'hours' => $t === 3 ? 2 : 3], [1, 2, 3]),
        ], range(1, 5));
    }
}
