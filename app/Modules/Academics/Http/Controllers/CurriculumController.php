<?php

namespace App\Modules\Academics\Http\Controllers;

use App\Models\User;
use App\Modules\Academics\Authorization\AcademicsPermissions;
use App\Modules\Academics\Http\AcademicsPresenter;
use App\Modules\Academics\Models\Course;
use App\Modules\Academics\Models\Curriculum;
use App\Modules\Academics\Services\AcademicsService;
use App\Modules\Institution\Models\Programme;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * @tags Academics: curriculum
 */
class CurriculumController extends Controller
{
    private const RELATIONS = ['programme', 'approver', 'entries.course.department'];

    public function __construct(private AcademicsService $academics) {}

    /**
     * Curriculum versions. Filters: `filter[programme]`, `filter[status]`.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(AcademicsPermissions::View);

        $curricula = Curriculum::query()
            ->with(['programme', 'approver'])
            ->withCount('entries')
            ->when($request->input('filter.programme'), fn ($query, $id) => $query->whereHas('programme', fn ($query) => $query->where('ulid', $id)))
            ->when($request->input('filter.status'), fn ($query, $status) => $query->where('status', $status))
            ->orderBy('programme_id')->orderByDesc('regulation')->orderByDesc('version')
            ->get();

        return ApiResponse::list($curricula, fn (Curriculum $curriculum): array => AcademicsPresenter::curriculum($curriculum));
    }

    public function show(Curriculum $curriculum): JsonResponse
    {
        Gate::authorize(AcademicsPermissions::View);

        return ApiResponse::data(AcademicsPresenter::curriculum($curriculum->load(self::RELATIONS), withEntries: true));
    }

    /**
     * New draft version. `copy_from` (curriculum id) copies its courses.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize(AcademicsPermissions::CurriculumManage);
        $validated = $request->validate([
            'programme_id' => ['required', 'string', 'size:26'],
            'regulation' => ['required', 'string', 'max:50'],
            'copy_from' => ['nullable', 'string', 'size:26'],
        ]);

        $programme = Programme::query()->where('ulid', $validated['programme_id'])->first() ?? throw new BusinessRuleException('Choose a programme.', 'PROGRAMME_INVALID', 422);
        $copyFrom = isset($validated['copy_from']) ? Curriculum::query()->with('entries')->where('ulid', $validated['copy_from'])->firstOrFail() : null;

        $curriculum = $this->academics->createCurriculum($programme->id, trim($validated['regulation']), $copyFrom);

        return ApiResponse::data(AcademicsPresenter::curriculum($curriculum->load(self::RELATIONS), withEntries: true), 201);
    }

    /**
     * Replace the course list of a draft: `entries[]` of `{course_id, term_number, category, is_elective}`.
     */
    public function entries(Request $request, Curriculum $curriculum): JsonResponse
    {
        Gate::authorize(AcademicsPermissions::CurriculumManage);
        $validated = $request->validate([
            'entries' => ['present', 'array', 'max:150'],
            'entries.*.course_id' => ['required', 'string', 'size:26', 'distinct'],
            'entries.*.term_number' => ['required', 'integer', 'min:1', 'max:21'],
            'entries.*.category' => ['nullable', 'string', 'max:30'],
            'entries.*.is_elective' => ['sometimes', 'boolean'],
        ]);

        $courses = Course::query()->whereIn('ulid', collect($validated['entries'])->pluck('course_id'))->pluck('id', 'ulid');

        if ($courses->count() !== count($validated['entries'])) {
            throw new BusinessRuleException('One of the courses was not found.', 'COURSE_INVALID', 422);
        }

        $this->academics->replaceEntries($curriculum, collect($validated['entries'])->map(fn (array $entry): array => ['course_id' => (int) $courses[$entry['course_id']]] + $entry)->all());

        return $this->show($curriculum);
    }

    public function submit(Curriculum $curriculum): JsonResponse
    {
        Gate::authorize(AcademicsPermissions::CurriculumManage);
        $this->academics->submitCurriculum($curriculum);

        return $this->show($curriculum);
    }

    /**
     * Approve, or send back to draft with `remarks`.
     */
    public function decide(Request $request, Curriculum $curriculum): JsonResponse
    {
        Gate::authorize(AcademicsPermissions::CurriculumApprove);
        $validated = $request->validate(['approve' => ['required', 'boolean'], 'remarks' => ['nullable', 'string', 'max:1000']]);

        /** @var User $user */
        $user = $request->user();
        $this->academics->decideCurriculum($curriculum, (bool) $validated['approve'], $validated['remarks'] ?? null, $user);

        return $this->show($curriculum);
    }

    public function destroy(Curriculum $curriculum): JsonResponse
    {
        Gate::authorize(AcademicsPermissions::CurriculumManage);

        if (! $curriculum->status->isEditable()) {
            throw new BusinessRuleException('Only a draft can be deleted.', 'CURRICULUM_LOCKED', 409);
        }

        $curriculum->delete();

        return ApiResponse::message('Curriculum draft deleted.');
    }
}
