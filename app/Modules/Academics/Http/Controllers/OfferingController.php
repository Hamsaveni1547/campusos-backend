<?php

namespace App\Modules\Academics\Http\Controllers;

use App\Modules\Academics\Authorization\AcademicsPermissions;
use App\Modules\Academics\Enums\TeachingComponent;
use App\Modules\Academics\Http\AcademicsPresenter;
use App\Modules\Academics\Models\Course;
use App\Modules\Academics\Models\CourseOffering;
use App\Modules\Academics\Models\Curriculum;
use App\Modules\Academics\Models\FacultyAllocation;
use App\Modules\Academics\Models\OfferingSection;
use App\Modules\Academics\Services\AcademicsService;
use App\Modules\HR\Models\Employee;
use App\Modules\Institution\Enums\RecordStatus;
use App\Modules\Institution\Models\Section;
use App\Modules\Institution\Models\Term;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Http\ApiResponse;
use App\Shared\Settings\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * @tags Academics: offerings & allocation
 */
class OfferingController extends Controller
{
    private const RELATIONS = ['course.department', 'term', 'sections.section.batch', 'sections.allocations.employee.user'];

    public function __construct(private AcademicsService $academics) {}

    /**
     * Courses offered in a term. Filters: `filter[term]` (default: current term), `filter[department]`,
     * `filter[section]`, `filter[search]`, `filter[unallocated]=1` (classes without a teacher).
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(AcademicsPermissions::View);
        $term = $this->term($request->input('filter.term'));

        $offerings = CourseOffering::query()
            ->with(self::RELATIONS)
            ->where('term_id', $term->id ?? 0)
            ->when($request->input('filter.department'), fn ($query, $id) => $query->whereHas('course.department', fn ($query) => $query->where('ulid', $id)))
            ->when($request->input('filter.section'), fn ($query, $id) => $query->whereHas('sections.section', fn ($query) => $query->where('ulid', $id)))
            ->when($request->boolean('filter.unallocated'), fn ($query) => $query->whereHas('sections', fn ($query) => $query->doesntHave('allocations')))
            ->when($request->input('filter.search'), fn ($query, $search) => $query->whereHas('course', fn ($query) => $query->where('code', 'like', "%{$search}%")->orWhere('title', 'like', "%{$search}%")))
            ->get()
            ->sortBy(fn (CourseOffering $offering): string => $offering->course->code)
            ->values();

        return ApiResponse::list($offerings, fn (CourseOffering $offering): array => AcademicsPresenter::offering($offering, $this->coverage($offering)));
    }

    public function show(CourseOffering $courseOffering): JsonResponse
    {
        Gate::authorize(AcademicsPermissions::View);
        $courseOffering->load(self::RELATIONS);

        return ApiResponse::data(AcademicsPresenter::offering($courseOffering, $this->coverage($courseOffering)));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(AcademicsPermissions::OfferingsManage);
        $validated = $request->validate([
            'course_id' => ['required', 'string', 'size:26'],
            'term_id' => ['required', 'string', 'size:26'],
            'section_ids' => ['present', 'array'],
            'section_ids.*' => ['string', 'size:26'],
            'is_elective' => ['sometimes', 'boolean'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $offering = $this->academics->offer(
            Course::query()->where('ulid', $validated['course_id'])->firstOrFail(),
            Term::query()->where('ulid', $validated['term_id'])->firstOrFail(),
            $this->sectionIds($validated['section_ids']),
            (bool) ($validated['is_elective'] ?? false),
            $validated['capacity'] ?? null,
        );

        return ApiResponse::data(AcademicsPresenter::offering($offering->load(self::RELATIONS)), 201);
    }

    public function update(Request $request, CourseOffering $courseOffering): JsonResponse
    {
        Gate::authorize(AcademicsPermissions::OfferingsManage);
        $validated = $request->validate([
            'section_ids' => ['sometimes', 'array'],
            'section_ids.*' => ['string', 'size:26'],
            'is_elective' => ['sometimes', 'boolean'],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'status' => ['sometimes', Rule::enum(RecordStatus::class)],
        ]);

        if (isset($validated['section_ids'])) {
            $this->academics->syncSections($courseOffering, $this->sectionIds($validated['section_ids']));
        }

        $courseOffering->update(array_intersect_key($validated, array_flip(['is_elective', 'capacity', 'status'])));

        return $this->show($courseOffering->refresh());
    }

    public function destroy(CourseOffering $courseOffering): JsonResponse
    {
        Gate::authorize(AcademicsPermissions::OfferingsManage);
        $this->academics->syncSections($courseOffering, []);
        $courseOffering->delete();

        return ApiResponse::message('Offering removed.');
    }

    /**
     * Offer all courses of one curriculum term to sections: `curriculum_id, term_id, term_number, section_ids[]`.
     */
    public function generate(Request $request): JsonResponse
    {
        Gate::authorize(AcademicsPermissions::OfferingsManage);
        $validated = $request->validate([
            'curriculum_id' => ['required', 'string', 'size:26'],
            'term_id' => ['required', 'string', 'size:26'],
            'term_number' => ['required', 'integer', 'min:1', 'max:21'],
            'section_ids' => ['required', 'array', 'min:1'],
            'section_ids.*' => ['string', 'size:26'],
        ]);

        $result = $this->academics->generateFromCurriculum(
            Curriculum::query()->where('ulid', $validated['curriculum_id'])->firstOrFail(),
            Term::query()->where('ulid', $validated['term_id'])->firstOrFail(),
            (int) $validated['term_number'],
            $this->sectionIds($validated['section_ids']),
        );

        return ApiResponse::data($result + ['message' => "{$result['created']} course(s) offered, {$result['skipped']} already offered."]);
    }

    /**
     * Allocate a teacher to a class: `employee_id, component (theory|lab|tutorial), weekly_hours`. May return a load warning.
     */
    public function allocate(Request $request, string $offeringSection): JsonResponse
    {
        Gate::authorize(AcademicsPermissions::AllocationsManage);
        $validated = $request->validate([
            'employee_id' => ['required', 'string', 'size:26'],
            'component' => ['required', Rule::enum(TeachingComponent::class)],
            'weekly_hours' => ['required', 'integer', 'min:1', 'max:30'],
        ]);

        $class = OfferingSection::query()->with('offering')->where('ulid', $offeringSection)->firstOrFail();
        $employee = Employee::query()->with('user')->where('ulid', $validated['employee_id'])->first() ?? throw new BusinessRuleException('Choose a teacher.', 'FACULTY_INVALID', 422);

        $result = $this->academics->allocate($class, $employee, TeachingComponent::from($validated['component']), (int) $validated['weekly_hours']);

        return ApiResponse::data(AcademicsPresenter::allocation($result['allocation']->load('employee.user')) + ['warning' => $result['warning']], 201);
    }

    public function deallocate(string $facultyAllocation): JsonResponse
    {
        Gate::authorize(AcademicsPermissions::AllocationsManage);
        FacultyAllocation::query()->where('ulid', $facultyAllocation)->firstOrFail()->delete();

        return ApiResponse::message('Teacher removed from the class.');
    }

    /**
     * Weekly teaching hours per teacher in a term. Filter: `filter[term]` (default current), `filter[department]`.
     */
    public function workload(Request $request, SettingsRepository $settings): JsonResponse
    {
        Gate::authorize(AcademicsPermissions::View);
        $term = $this->term($request->input('filter.term'));
        $limit = (int) $settings->get('academics', 'max_weekly_hours');

        $rows = FacultyAllocation::query()
            ->with(['employee.user', 'employee.department', 'offeringSection.offering.course', 'offeringSection.section'])
            ->whereHas('offeringSection.offering', fn ($query) => $query->where('term_id', $term->id ?? 0))
            ->when($request->input('filter.department'), fn ($query, $id) => $query->whereHas('employee.department', fn ($query) => $query->where('ulid', $id)))
            ->get()
            ->groupBy('employee_id')
            ->map(function ($allocations) use ($limit): array {
                $employee = $allocations->first()->employee;
                $hours = (int) $allocations->sum('weekly_hours');

                return [
                    'employee_id' => $employee->ulid,
                    'name' => $employee->user->name,
                    'department' => $employee->department?->name,
                    'weekly_hours' => $hours,
                    'over_limit' => $limit > 0 && $hours > $limit,
                    'classes' => $allocations->map(fn (FacultyAllocation $allocation): array => [
                        'course' => $allocation->offeringSection->offering->course->code,
                        'section' => $allocation->offeringSection->section->name,
                        'component' => $allocation->component->value,
                        'weekly_hours' => $allocation->weekly_hours,
                    ])->values()->all(),
                ];
            })
            ->sortByDesc('weekly_hours')
            ->values();

        return ApiResponse::data(['term' => $term === null ? null : ['id' => $term->ulid, 'name' => $term->name], 'limit' => $limit, 'faculty' => $rows]);
    }

    private function term(?string $ulid): ?Term
    {
        return $ulid ? Term::query()->where('ulid', $ulid)->first() : Term::query()->where('is_current', true)->first();
    }

    /**
     * @param  list<string>  $ulids
     * @return list<int>
     */
    private function sectionIds(array $ulids): array
    {
        $ids = Section::query()->whereIn('ulid', $ulids)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if (count($ids) !== count(array_unique($ulids))) {
            throw new BusinessRuleException('One of the sections was not found.', 'SECTION_INVALID', 422);
        }

        return $ids;
    }

    /**
     * @return array<int, int>
     */
    private function coverage(CourseOffering $offering): array
    {
        return $offering->sections->mapWithKeys(fn (OfferingSection $section): array => [$section->id => $this->academics->coveragePercent($section->setRelation('offering', $offering))])->all();
    }
}
