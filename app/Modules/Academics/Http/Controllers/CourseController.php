<?php

namespace App\Modules\Academics\Http\Controllers;

use App\Modules\Academics\Authorization\AcademicsPermissions;
use App\Modules\Academics\Enums\CourseType;
use App\Modules\Academics\Enums\CurriculumStatus;
use App\Modules\Academics\Enums\TeachingComponent;
use App\Modules\Academics\Http\AcademicsPresenter;
use App\Modules\Academics\Models\Course;
use App\Modules\Academics\Services\AcademicsService;
use App\Modules\Institution\Enums\RecordStatus;
use App\Modules\Institution\Models\Department;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Http\ApiResponse;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * @tags Academics: courses
 */
class CourseController extends Controller
{
    public function __construct(private AcademicsService $academics) {}

    /**
     * Option lists so clients never hard-code them.
     */
    public function options(): JsonResponse
    {
        return ApiResponse::data([
            'course_types' => collect(CourseType::cases())->map(fn (CourseType $type): array => ['value' => $type->value, 'label' => $type->label()])->values(),
            'components' => collect(TeachingComponent::cases())->map(fn (TeachingComponent $component): array => ['value' => $component->value, 'label' => ucfirst($component->value)])->values(),
            'curriculum_statuses' => collect(CurriculumStatus::cases())->map(fn (CurriculumStatus $status): array => ['value' => $status->value, 'label' => ucfirst($status->value)])->values(),
            'categories' => collect((array) config('campusos.academics.course_categories'))->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values(),
        ]);
    }

    /**
     * Courses. Filters: `filter[search]`, `filter[department]`, `filter[type]`, `filter[status]`, `filter[regulation]`.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(AcademicsPermissions::View);

        $courses = Course::query()
            ->with('department')
            ->when($request->input('filter.department'), fn ($query, $id) => $query->whereHas('department', fn ($query) => $query->where('ulid', $id)))
            ->when($request->input('filter.type'), fn ($query, $type) => $query->where('type', $type))
            ->when($request->input('filter.status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->input('filter.regulation'), fn ($query, $regulation) => $query->where('regulation', $regulation))
            ->when($request->input('filter.search'), fn ($query, $search) => $query->where(fn ($query) => $query->where('code', 'like', "%{$search}%")->orWhere('title', 'like', "%{$search}%")))
            ->orderBy('code')
            ->paginate(min((int) $request->integer('per_page', 50), 200));

        return ApiResponse::paginated($courses, fn (Course $course): array => AcademicsPresenter::course($course));
    }

    public function show(Course $course): JsonResponse
    {
        Gate::authorize(AcademicsPermissions::View);

        return ApiResponse::data(AcademicsPresenter::course($course->load(['department', 'units.topics']), withSyllabus: true));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(AcademicsPermissions::CoursesManage);
        $course = Course::query()->create($this->attributes($request->validate($this->rules())));

        return ApiResponse::data(AcademicsPresenter::course($course->load(['department', 'units.topics']), withSyllabus: true), 201);
    }

    public function update(Request $request, Course $course): JsonResponse
    {
        Gate::authorize(AcademicsPermissions::CoursesManage);
        $course->update($this->attributes($request->validate($this->rules($course))));

        return $this->show($course);
    }

    public function destroy(Course $course): JsonResponse
    {
        Gate::authorize(AcademicsPermissions::CoursesManage);

        return ApiResponse::deleteUnlessUsed($course, ['offerings' => 'offerings', 'curriculumEntries' => 'curricula using it'], 'course');
    }

    /**
     * Replace the syllabus: `units[]` of `{title, hours, topics[]: {title, hours}}`.
     */
    public function syllabus(Request $request, Course $course): JsonResponse
    {
        Gate::authorize(AcademicsPermissions::CoursesManage);

        $validated = $request->validate([
            'units' => ['present', 'array', 'max:20'],
            'units.*.title' => ['required', 'string', 'max:200'],
            'units.*.hours' => ['nullable', 'integer', 'min:0', 'max:200'],
            'units.*.topics' => ['sometimes', 'array', 'max:60'],
            'units.*.topics.*.title' => ['required', 'string', 'max:255'],
            'units.*.topics.*.hours' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'textbooks' => ['sometimes', 'array', 'max:20'],
            'textbooks.*' => ['string', 'max:300'],
        ]);

        $this->academics->replaceSyllabus($course, $validated['units']);
        $course->update(array_intersect_key($validated, array_flip(['description', 'textbooks'])));

        return $this->show($course);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?Course $course = null): array
    {
        $required = $course === null ? 'required' : 'sometimes';

        return [
            'department_id' => [$required, 'string', 'size:26'],
            'code' => [$required, 'string', 'max:20', 'regex:/^[A-Za-z0-9\-_.]+$/', Rule::unique('courses', 'code')->where('institution_id', app(CurrentInstitution::class)->id())->ignore($course?->id)],
            'title' => [$required, 'string', 'max:200'],
            'type' => [$required, Rule::enum(CourseType::class)],
            'credits' => [$required, 'numeric', 'min:0', 'max:30'],
            'lecture_hours' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'tutorial_hours' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'practical_hours' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'regulation' => ['sometimes', 'nullable', 'string', 'max:50'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::enum(RecordStatus::class)],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(array $validated): array
    {
        if (isset($validated['department_id'])) {
            $department = Department::query()->where('ulid', $validated['department_id'])->first();

            if ($department === null) {
                throw new BusinessRuleException('Choose a department.', 'DEPARTMENT_INVALID', 422);
            }

            $validated['department_id'] = $department->id;
        }

        if (isset($validated['code'])) {
            $validated['code'] = strtoupper($validated['code']);
        }

        return $validated;
    }
}
