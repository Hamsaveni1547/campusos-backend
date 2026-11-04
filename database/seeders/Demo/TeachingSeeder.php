<?php

namespace Database\Seeders\Demo;

use App\Modules\Academics\Enums\TeachingComponent;
use App\Modules\Academics\Models\Course;
use App\Modules\Academics\Models\CourseOffering;
use App\Modules\Academics\Models\OfferingSection;
use App\Modules\Academics\Services\AcademicsService;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use App\Modules\Institution\Models\Batch;
use App\Modules\Institution\Models\Term;
use Database\Seeders\Demo\Data\CourseCatalogue;
use Database\Seeders\Support\CampusSeeder;
use Illuminate\Support\Carbon;

/**
 * Course offerings for Even 2026 (last semester) and Odd 2026 (current) to each batch's semester,
 * teachers from the programme's department, and syllabus coverage so far this semester.
 */
class TeachingSeeder extends CampusSeeder
{
    public const PAST_TERM = 'Even Semester 2026';

    public const CURRENT_TERM = 'Odd Semester 2026';

    protected function seed(): void
    {
        if (CourseOffering::query()->exists()) {
            return;
        }

        $academics = app(AcademicsService::class);
        $past = Term::query()->where('name', self::PAST_TERM)->firstOrFail();
        $current = Term::query()->where('name', self::CURRENT_TERM)->firstOrFail();
        $catalogue = CourseCatalogue::all();
        $next = [];

        $this->as($this->admin(), function () use ($academics, $past, $current, $catalogue, &$next): void {
            foreach (Batch::query()->with(['programme.department', 'sections'])->get() as $batch) {
                foreach ([[$past, (2026 - $batch->admission_year) * 2], [$current, (2026 - $batch->admission_year) * 2 + 1]] as [$term, $number]) {
                    $courses = $catalogue[$batch->programme->code][$number] ?? [];

                    if ($number < 1 || $courses === []) {
                        continue;
                    }

                    $teachers = Employee::query()->where('department_id', $batch->programme->department_id)->where('staff_type', 'teaching')
                        ->where('status', EmployeeStatus::Active)->whereHas('user', fn ($q) => $q->where('email', 'not like', 'principal@%'))->orderBy('id')->get()->values();

                    foreach ($courses as [$code]) {
                        $offering = $academics->offer(Course::query()->where('code', $code)->firstOrFail(), $term, $batch->sections->pluck('id')->all());

                        foreach (OfferingSection::query()->where('course_offering_id', $offering->id)->get() as $class) {
                            $key = $batch->programme->department_id;
                            $teacher = $teachers[($next[$key] = ($next[$key] ?? -1) + 1) % $teachers->count()];
                            $academics->allocate($class, $teacher, TeachingComponent::Theory, 3);
                        }
                    }
                }
            }
        });

        $this->coverage($academics, $current);
    }

    /**
     * Teachers have covered roughly the first two units of each current class.
     */
    private function coverage(AcademicsService $academics, Term $term): void
    {
        OfferingSection::query()->with(['offering.course.units.topics', 'allocations.employee.user'])
            ->whereHas('offering', fn ($q) => $q->where('term_id', $term->id))->get()
            ->each(function (OfferingSection $class) use ($academics): void {
                $teacher = $class->allocations->first()?->employee?->user;
                $topics = $class->offering->course->units->sortBy('number')->flatMap->topics->values();

                if ($teacher === null || $topics->isEmpty()) {
                    return;
                }

                $this->as($teacher, function () use ($academics, $class, $topics, $teacher): void {
                    foreach ($topics->take(5 + $class->id % 3) as $index => $topic) {
                        $academics->cover($class, $topic->id, Carbon::parse('2026-08-10')->addWeekdays($index * 4), $index === 0 ? 'Introductory lecture with examples' : null, $this->fresh($teacher));
                    }
                });
            });
    }
}
