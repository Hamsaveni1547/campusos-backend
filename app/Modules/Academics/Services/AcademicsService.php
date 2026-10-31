<?php

namespace App\Modules\Academics\Services;

use App\Models\User;
use App\Modules\Academics\Authorization\AcademicsPermissions;
use App\Modules\Academics\Enums\CurriculumStatus;
use App\Modules\Academics\Enums\TeachingComponent;
use App\Modules\Academics\Models\Course;
use App\Modules\Academics\Models\CourseOffering;
use App\Modules\Academics\Models\Curriculum;
use App\Modules\Academics\Models\CurriculumCourse;
use App\Modules\Academics\Models\FacultyAllocation;
use App\Modules\Academics\Models\OfferingSection;
use App\Modules\Academics\Models\TopicCoverage;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Enums\StaffType;
use App\Modules\HR\Models\Employee;
use App\Modules\Institution\Enums\PeriodStatus;
use App\Modules\Institution\Models\Section;
use App\Modules\Institution\Models\Term;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Settings\SettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Curriculum versions, term offerings, faculty allocation, syllabus coverage.
 */
class AcademicsService
{
    public function __construct(private SettingsRepository $settings) {}

    // ----- syllabus --------------------------------------------------------

    /**
     * Replace the whole syllabus: units with their topics.
     *
     * @param  list<array{title: string, hours?: int|null, topics?: list<array{title: string, hours?: float|int|null}>}>  $units
     */
    public function replaceSyllabus(Course $course, array $units): void
    {
        if (TopicCoverage::query()->whereIn('syllabus_topic_id', $course->topics()->select('syllabus_topics.id'))->exists()) {
            throw new BusinessRuleException('Topics of this syllabus are already marked as taught. Add a new course version instead.', 'SYLLABUS_IN_USE', 409);
        }

        DB::transaction(function () use ($course, $units): void {
            $course->units()->delete();

            foreach ($units as $index => $unit) {
                $model = $course->units()->create([
                    'institution_id' => $course->institution_id,
                    'number' => $index + 1,
                    'title' => $unit['title'],
                    'hours' => (int) ($unit['hours'] ?? 0),
                ]);

                foreach ($unit['topics'] ?? [] as $position => $topic) {
                    $model->topics()->create([
                        'institution_id' => $course->institution_id,
                        'title' => $topic['title'],
                        'hours' => $topic['hours'] ?? 1,
                        'position' => $position,
                    ]);
                }
            }
        });
    }

    // ----- curriculum ------------------------------------------------------

    /**
     * New draft version for a programme + regulation, optionally copying an earlier version's courses.
     */
    public function createCurriculum(int $programmeId, string $regulation, ?Curriculum $copyFrom = null): Curriculum
    {
        return DB::transaction(function () use ($programmeId, $regulation, $copyFrom): Curriculum {
            $version = (int) Curriculum::query()->where('programme_id', $programmeId)->where('regulation', $regulation)->max('version') + 1;

            $curriculum = Curriculum::query()->create(['programme_id' => $programmeId, 'regulation' => $regulation, 'version' => $version]);

            foreach ($copyFrom->entries ?? [] as $entry) {
                $curriculum->entries()->create([
                    'institution_id' => $curriculum->institution_id,
                    'course_id' => $entry->course_id,
                    'term_number' => $entry->term_number,
                    'category' => $entry->category,
                    'is_elective' => $entry->is_elective,
                ]);
            }

            return $curriculum;
        });
    }

    /**
     * @param  list<array{course_id: int, term_number: int, category?: string|null, is_elective?: bool}>  $entries
     */
    public function replaceEntries(Curriculum $curriculum, array $entries): void
    {
        if (! $curriculum->status->isEditable()) {
            throw new BusinessRuleException('Only a draft curriculum can be changed. Create a new version.', 'CURRICULUM_LOCKED', 409);
        }

        $maxTerm = $curriculum->programme->total_terms;

        foreach ($entries as $entry) {
            if ($entry['term_number'] > $maxTerm) {
                throw new BusinessRuleException("The programme has only {$maxTerm} terms.", 'TERM_OUT_OF_RANGE', 422);
            }
        }

        DB::transaction(function () use ($curriculum, $entries): void {
            $curriculum->entries()->delete();

            foreach ($entries as $entry) {
                $curriculum->entries()->create([
                    'institution_id' => $curriculum->institution_id,
                    'course_id' => $entry['course_id'],
                    'term_number' => $entry['term_number'],
                    'category' => $entry['category'] ?? 'core',
                    'is_elective' => (bool) ($entry['is_elective'] ?? false),
                ]);
            }
        });
    }

    public function submitCurriculum(Curriculum $curriculum): Curriculum
    {
        if ($curriculum->status !== CurriculumStatus::Draft) {
            throw new BusinessRuleException('Only a draft can be submitted.', 'INVALID_STATUS', 409);
        }

        if (! $curriculum->entries()->exists()) {
            throw new BusinessRuleException('Add courses before submitting.', 'CURRICULUM_EMPTY', 422);
        }

        $curriculum->forceFill(['status' => CurriculumStatus::Submitted, 'remarks' => null])->save();

        return $curriculum;
    }

    /**
     * Approve (the previously approved version of the same regulation is archived) or send back to draft.
     */
    public function decideCurriculum(Curriculum $curriculum, bool $approve, ?string $remarks, User $approver): Curriculum
    {
        if ($curriculum->status !== CurriculumStatus::Submitted) {
            throw new BusinessRuleException('Only a submitted curriculum can be approved or returned.', 'INVALID_STATUS', 409);
        }

        if (! $approve && blank($remarks)) {
            throw new BusinessRuleException('Say what needs to change.', 'REMARKS_REQUIRED', 422);
        }

        return DB::transaction(function () use ($curriculum, $approve, $remarks, $approver): Curriculum {
            if ($approve) {
                Curriculum::query()
                    ->where('programme_id', $curriculum->programme_id)
                    ->where('regulation', $curriculum->regulation)
                    ->where('status', CurriculumStatus::Approved)
                    ->update(['status' => CurriculumStatus::Archived]);

                $curriculum->forceFill(['status' => CurriculumStatus::Approved, 'remarks' => $remarks, 'approved_by' => $approver->id, 'approved_at' => now()])->save();
            } else {
                $curriculum->forceFill(['status' => CurriculumStatus::Draft, 'remarks' => $remarks])->save();
            }

            return $curriculum;
        });
    }

    // ----- offerings -------------------------------------------------------

    /**
     * @param  list<int>  $sectionIds
     */
    public function offer(Course $course, Term $term, array $sectionIds, bool $isElective = false, ?int $capacity = null, ?int $curriculumId = null): CourseOffering
    {
        if ($term->status === PeriodStatus::Closed) {
            throw new BusinessRuleException('This term is closed.', 'TERM_CLOSED', 409);
        }

        if (CourseOffering::query()->where('course_id', $course->id)->where('term_id', $term->id)->exists()) {
            throw new BusinessRuleException('This course is already offered in the term. Add sections to it instead.', 'OFFERING_EXISTS', 409);
        }

        return DB::transaction(function () use ($course, $term, $sectionIds, $isElective, $capacity, $curriculumId): CourseOffering {
            $offering = CourseOffering::query()->create([
                'course_id' => $course->id,
                'term_id' => $term->id,
                'curriculum_id' => $curriculumId,
                'is_elective' => $isElective,
                'capacity' => $capacity,
            ]);

            $this->syncSections($offering, $sectionIds);

            return $offering;
        });
    }

    /**
     * @param  list<int>  $sectionIds
     */
    public function syncSections(CourseOffering $offering, array $sectionIds): void
    {
        $sectionIds = array_values(array_unique($sectionIds));
        $removed = $offering->sections()->whereNotIn('section_id', $sectionIds)->get();

        foreach ($removed as $offeringSection) {
            if ($offeringSection->allocations()->exists() || $offeringSection->coverage()->exists()) {
                throw new BusinessRuleException('Section '.$offeringSection->section->name.' already has faculty or syllabus progress.', 'SECTION_IN_USE', 409);
            }

            $offeringSection->delete();
        }

        $existing = $offering->sections()->pluck('section_id')->all();

        foreach (array_diff($sectionIds, $existing) as $sectionId) {
            $offering->sections()->create(['section_id' => $sectionId, 'institution_id' => $offering->institution_id]);
        }
    }

    /**
     * Offer every course of one term of an approved curriculum to the given sections.
     *
     * @param  list<int>  $sectionIds
     * @return array{created: int, skipped: int}
     */
    public function generateFromCurriculum(Curriculum $curriculum, Term $term, int $termNumber, array $sectionIds): array
    {
        if ($curriculum->status !== CurriculumStatus::Approved) {
            throw new BusinessRuleException('Only an approved curriculum can be used.', 'CURRICULUM_NOT_APPROVED', 409);
        }

        $sectionsOk = Section::query()->whereIn('id', $sectionIds)->whereHas('batch', fn ($query) => $query->where('programme_id', $curriculum->programme_id))->count();

        if ($sectionsOk !== count(array_unique($sectionIds))) {
            throw new BusinessRuleException('Choose sections of this programme only.', 'SECTION_MISMATCH', 422);
        }

        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($curriculum, $term, $termNumber, $sectionIds, &$created, &$skipped): void {
            $entries = $curriculum->entries()->with('course')->where('term_number', $termNumber)->get();

            foreach ($entries as $entry) {
                /** @var CurriculumCourse $entry */
                $offering = CourseOffering::query()->where('course_id', $entry->course_id)->where('term_id', $term->id)->first();

                if ($offering === null) {
                    $this->offer($entry->course, $term, $sectionIds, $entry->is_elective, null, $curriculum->id);
                    $created++;
                } else {
                    $this->syncSections($offering, array_values(array_unique([...$offering->sections()->pluck('section_id')->all(), ...$sectionIds])));
                    $skipped++;
                }
            }
        });

        return ['created' => $created, 'skipped' => $skipped];
    }

    // ----- allocation ------------------------------------------------------

    /**
     * Allocate a teacher. Returns the allocation and a warning when the weekly load goes over the limit.
     *
     * @return array{allocation: FacultyAllocation, warning: string|null}
     */
    public function allocate(OfferingSection $offeringSection, Employee $employee, TeachingComponent $component, int $weeklyHours): array
    {
        if ($employee->status !== EmployeeStatus::Active || $employee->staff_type !== StaffType::Teaching) {
            throw new BusinessRuleException('Only active teaching staff can be allocated.', 'FACULTY_NOT_ELIGIBLE', 422);
        }

        if ($offeringSection->allocations()->where('employee_id', $employee->id)->where('component', $component)->exists()) {
            throw new BusinessRuleException('This teacher already has this part of the class.', 'ALREADY_ALLOCATED', 409);
        }

        $allocation = $offeringSection->allocations()->create([
            'institution_id' => $offeringSection->institution_id,
            'employee_id' => $employee->id,
            'component' => $component,
            'weekly_hours' => $weeklyHours,
        ]);

        $load = $this->weeklyLoad($employee, $offeringSection->offering->term_id);
        $limit = (int) $this->settings->get('academics', 'max_weekly_hours');

        return [
            'allocation' => $allocation,
            'warning' => $limit > 0 && $load > $limit ? "{$employee->user->name} now teaches {$load} hours a week (limit {$limit})." : null,
        ];
    }

    public function weeklyLoad(Employee $employee, int $termId): int
    {
        return (int) FacultyAllocation::query()
            ->where('employee_id', $employee->id)
            ->whereHas('offeringSection.offering', fn ($query) => $query->where('term_id', $termId))
            ->sum('weekly_hours');
    }

    // ----- teaching --------------------------------------------------------

    /**
     * May this user teach-side act on the class (track syllabus, share materials)?
     */
    public function teaches(User $user, OfferingSection $offeringSection): bool
    {
        if ($user->can(AcademicsPermissions::OfferingsManage)) {
            return true;
        }

        return $offeringSection->allocations()->whereHas('employee', fn ($query) => $query->where('user_id', $user->id))->exists();
    }

    public function cover(OfferingSection $offeringSection, int $topicId, Carbon $coveredOn, ?string $notes, User $teacher): TopicCoverage
    {
        $belongs = $offeringSection->offering->course->topics()->where('syllabus_topics.id', $topicId)->exists();

        if (! $belongs) {
            throw new BusinessRuleException('This topic is not in the course syllabus.', 'TOPIC_INVALID', 422);
        }

        return TopicCoverage::query()->updateOrCreate(
            ['offering_section_id' => $offeringSection->id, 'syllabus_topic_id' => $topicId],
            ['institution_id' => $offeringSection->institution_id, 'covered_on' => $coveredOn, 'notes' => $notes, 'covered_by' => $teacher->id],
        );
    }

    /**
     * Share of syllabus topics covered for a class, 0–100.
     */
    public function coveragePercent(OfferingSection $offeringSection): int
    {
        $total = $offeringSection->offering->course->topics()->count();

        return $total === 0 ? 0 : (int) round($offeringSection->coverage()->count() * 100 / $total);
    }
}
