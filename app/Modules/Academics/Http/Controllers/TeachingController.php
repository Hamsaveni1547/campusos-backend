<?php

namespace App\Modules\Academics\Http\Controllers;

use App\Models\User;
use App\Modules\Academics\Authorization\AcademicsPermissions;
use App\Modules\Academics\Http\AcademicsPresenter;
use App\Modules\Academics\Models\CourseMaterial;
use App\Modules\Academics\Models\FacultyAllocation;
use App\Modules\Academics\Models\OfferingSection;
use App\Modules\Academics\Models\SyllabusTopic;
use App\Modules\Academics\Models\SyllabusUnit;
use App\Modules\Academics\Models\TopicCoverage;
use App\Modules\Academics\Services\AcademicsService;
use App\Modules\Institution\Models\Term;
use App\Modules\Students\Models\Student;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Files\StoredFile;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

/**
 * Classes as seen by the teacher and the student: syllabus progress and materials.
 *
 * @tags Academics: teaching
 */
class TeachingController extends Controller
{
    public function __construct(private AcademicsService $academics) {}

    /**
     * The signed-in teacher's classes. Filter: `filter[term]` (default current).
     */
    public function myTeaching(Request $request): JsonResponse
    {
        $term = $this->term($request->input('filter.term'));

        $allocations = FacultyAllocation::query()
            ->with(['offeringSection.offering.course', 'offeringSection.section.batch', 'offeringSection.offering.term'])
            ->whereHas('employee', fn ($query) => $query->where('user_id', $this->user($request)->id))
            ->whereHas('offeringSection.offering', fn ($query) => $query->where('term_id', $term->id ?? 0))
            ->get();

        return ApiResponse::data([
            'term' => $term === null ? null : ['id' => $term->ulid, 'name' => $term->name],
            'weekly_hours' => (int) $allocations->sum('weekly_hours'),
            'classes' => $allocations->groupBy('offering_section_id')->map(function ($group): array {
                /** @var FacultyAllocation $first */
                $first = $group->first();
                $class = $first->offeringSection;

                return [
                    'id' => $class->ulid,
                    'course' => AcademicsPresenter::course($class->offering->course->loadMissing('department')),
                    'section' => ['name' => $class->section->name, 'batch' => $class->section->batch->name],
                    'components' => $group->map(fn (FacultyAllocation $allocation): array => ['component' => $allocation->component->value, 'weekly_hours' => $allocation->weekly_hours])->values()->all(),
                    'coverage_percent' => $this->academics->coveragePercent($class),
                ];
            })->values()->all(),
        ]);
    }

    /**
     * The signed-in student's courses this term (their section's offerings).
     */
    public function myCourses(Request $request): JsonResponse
    {
        $student = Student::query()->where('user_id', $this->user($request)->id)->first();

        if ($student === null || $student->section_id === null) {
            return ApiResponse::data(['term' => null, 'courses' => []]);
        }

        $term = $this->term(null);
        $classes = OfferingSection::query()
            ->with(['offering.course.department', 'allocations.employee.user'])
            ->where('section_id', $student->section_id)
            ->whereHas('offering', fn ($query) => $query->where('term_id', $term->id ?? 0))
            ->get();

        return ApiResponse::data([
            'term' => $term === null ? null : ['id' => $term->ulid, 'name' => $term->name],
            'courses' => $classes->map(fn (OfferingSection $class): array => [
                'id' => $class->ulid,
                'course' => AcademicsPresenter::course($class->offering->course),
                'faculty' => $class->allocations->map(fn (FacultyAllocation $allocation): array => ['name' => $allocation->employee->user->name, 'component' => $allocation->component->value])->values()->all(),
                'coverage_percent' => $this->academics->coveragePercent($class),
            ])->sortBy('course.code')->values()->all(),
        ]);
    }

    /**
     * One class: syllabus with covered topics, and materials. Teachers, staff who can view, and its students.
     */
    public function show(Request $request, string $offeringSection): JsonResponse
    {
        $class = $this->class($offeringSection);
        $user = $this->user($request);
        $teaches = $this->academics->teaches($user, $class);
        $isStudent = Student::query()->where('user_id', $user->id)->where('section_id', $class->section_id)->exists();

        if (! $teaches && ! $isStudent && ! $user->can(AcademicsPermissions::View)) {
            abort(404);
        }

        $class->load(['offering.course.department', 'offering.course.units.topics', 'offering.term', 'section.batch', 'allocations.employee.user', 'coverage.teacher']);
        $covered = $class->coverage->keyBy('syllabus_topic_id');
        $materials = $class->offering->materials()->with(['file', 'unit'])->when(! $teaches, fn ($query) => $query->where('is_published', true))->get();

        return ApiResponse::data([
            'id' => $class->ulid,
            'course' => AcademicsPresenter::course($class->offering->course),
            'term' => ['id' => $class->offering->term->ulid, 'name' => $class->offering->term->name],
            'section' => ['name' => $class->section->name, 'batch' => $class->section->batch->name],
            'faculty' => $class->allocations->map(fn (FacultyAllocation $allocation): array => AcademicsPresenter::allocation($allocation))->values()->all(),
            'can_teach' => $teaches,
            'coverage_percent' => $this->academics->coveragePercent($class),
            'units' => $class->offering->course->units->map(fn (SyllabusUnit $unit): array => [
                'id' => $unit->ulid,
                'number' => $unit->number,
                'title' => $unit->title,
                'topics' => $unit->topics->map(function (SyllabusTopic $topic) use ($covered): array {
                    /** @var TopicCoverage|null $done */
                    $done = $covered->get($topic->id);

                    return ['id' => $topic->ulid, 'title' => $topic->title, 'hours' => (float) $topic->hours, 'covered_on' => $done?->covered_on->toDateString(), 'covered_by' => $done?->teacher?->name, 'notes' => $done?->notes];
                })->values()->all(),
            ])->values()->all(),
            'materials' => $materials->map(fn (CourseMaterial $material): array => AcademicsPresenter::material($material))->values()->all(),
        ]);
    }

    /**
     * Mark a topic taught: `topic_id, covered_on?, notes?`.
     */
    public function cover(Request $request, string $offeringSection): JsonResponse
    {
        $class = $this->teachable($request, $offeringSection, AcademicsPermissions::SyllabusTrack);
        $validated = $request->validate(['topic_id' => ['required', 'string', 'size:26'], 'covered_on' => ['sometimes', 'date', 'before_or_equal:today'], 'notes' => ['nullable', 'string', 'max:500']]);
        $topic = SyllabusTopic::query()->where('ulid', $validated['topic_id'])->firstOrFail();

        $this->academics->cover($class, $topic->id, Carbon::parse($validated['covered_on'] ?? now()), $validated['notes'] ?? null, $this->user($request));

        return $this->show($request, $offeringSection);
    }

    public function uncover(Request $request, string $offeringSection, string $topic): JsonResponse
    {
        $class = $this->teachable($request, $offeringSection, AcademicsPermissions::SyllabusTrack);
        $topicId = SyllabusTopic::query()->where('ulid', $topic)->value('id');
        TopicCoverage::query()->where('offering_section_id', $class->id)->where('syllabus_topic_id', $topicId)->delete();

        return $this->show($request, $offeringSection);
    }

    /**
     * Share material with every section of the offering: `title, unit_id?, file_id? (purpose document) | url?, is_published?`.
     */
    public function addMaterial(Request $request, string $offeringSection): JsonResponse
    {
        $class = $this->teachable($request, $offeringSection, AcademicsPermissions::MaterialsManage);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'unit_id' => ['nullable', 'string', 'size:26'],
            'file_id' => ['nullable', 'required_without:url', 'string', 'size:26'],
            'url' => ['nullable', 'required_without:file_id', 'url:http,https', 'max:500'],
            'is_published' => ['sometimes', 'boolean'],
        ]);

        $file = null;

        if (isset($validated['file_id'])) {
            $file = StoredFile::query()->where('ulid', $validated['file_id'])->where('uploaded_by', $this->user($request)->id)->whereIn('purpose', ['document', 'attachment'])->first()
                ?? throw new BusinessRuleException('Upload the file first.', 'FILE_INVALID', 422);
        }

        $class->offering->materials()->create([
            'institution_id' => $class->institution_id,
            'title' => $validated['title'],
            'syllabus_unit_id' => isset($validated['unit_id']) ? $class->offering->course->units()->where('ulid', $validated['unit_id'])->value('id') : null,
            'file_id' => $file?->id,
            'url' => $validated['url'] ?? null,
            'is_published' => $validated['is_published'] ?? true,
        ]);

        return $this->show($request, $offeringSection);
    }

    public function removeMaterial(Request $request, string $offeringSection, string $material): JsonResponse
    {
        $class = $this->teachable($request, $offeringSection, AcademicsPermissions::MaterialsManage);
        $class->offering->materials()->where('ulid', $material)->firstOrFail()->delete();

        return $this->show($request, $offeringSection);
    }

    private function class(string $ulid): OfferingSection
    {
        return OfferingSection::query()->with('offering.course')->where('ulid', $ulid)->firstOrFail();
    }

    private function teachable(Request $request, string $ulid, string $permission): OfferingSection
    {
        $class = $this->class($ulid);
        $user = $this->user($request);

        if (! $user->can($permission) || ! $this->academics->teaches($user, $class)) {
            abort(403, 'Only teachers of this class can do this.');
        }

        return $class;
    }

    private function term(?string $ulid): ?Term
    {
        return $ulid ? Term::query()->where('ulid', $ulid)->first() : Term::query()->where('is_current', true)->first();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
