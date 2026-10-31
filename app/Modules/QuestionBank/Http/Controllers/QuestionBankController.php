<?php

namespace App\Modules\QuestionBank\Http\Controllers;

use App\Models\User;
use App\Modules\Academics\Models\Course;
use App\Modules\Exams\Models\ExamSchedule;
use App\Modules\QuestionBank\Authorization\QuestionBankPermissions as P;
use App\Modules\QuestionBank\Models\PaperAccessLog;
use App\Modules\QuestionBank\Models\PaperBlueprint;
use App\Modules\QuestionBank\Models\Question;
use App\Modules\QuestionBank\Models\QuestionPaper;
use App\Modules\QuestionBank\Services\QuestionBankService;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Http\ApiResponse;
use App\Shared\Settings\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * @tags Question bank
 */
class QuestionBankController extends Controller
{
    public function __construct(private QuestionBankService $bank) {}

    /**
     * Question types, difficulties, Bloom levels, paper statuses and the release window.
     */
    public function options(SettingsRepository $settings): JsonResponse
    {
        $labels = fn (array $values): array => array_map(fn (string $value): array => ['value' => $value, 'label' => $value === 'mcq' ? 'Multiple choice' : ucfirst($value)], $values);

        return ApiResponse::data([
            'types' => $labels(Question::TYPES),
            'difficulties' => $labels(Question::DIFFICULTIES),
            'bloom_levels' => $labels(Question::BLOOM_LEVELS),
            'paper_statuses' => $labels(QuestionPaper::STATUSES),
            'release_minutes_before' => (int) $settings->get('qbank', 'release_minutes_before'),
        ]);
    }

    // ----- questions -------------------------------------------------------

    /**
     * Questions of a course. Filters: `filter[type]`, `filter[unit]`, `filter[difficulty]`, `filter[status]` (default active).
     */
    public function questions(Request $request, string $course): JsonResponse
    {
        $this->authorizeAny($request, [P::QuestionsContribute, P::QuestionsManage, P::PapersSet, P::PapersReview]);
        $model = $this->course($course);
        $filters = (array) $request->input('filter', []);
        $user = $this->user($request);

        $questions = Question::query()->where('course_id', $model->id)
            ->where('status', $filters['status'] ?? 'active')
            ->when($filters['type'] ?? null, fn ($query, $value) => $query->where('type', $value))
            ->when($filters['unit'] ?? null, fn ($query, $value) => $query->where('unit', (int) $value))
            ->when($filters['difficulty'] ?? null, fn ($query, $value) => $query->where('difficulty', $value))
            ->orderBy('unit')->orderBy('marks')->orderBy('id')
            ->paginate(50);

        return ApiResponse::paginated($questions, fn (Question $q): array => [...$this->question($q), 'can_edit' => $this->bank->canEdit($user, $q)]);
    }

    /**
     * Number of active questions by unit, type and Bloom level, to spot gaps before generating papers.
     */
    public function coverage(Request $request, string $course): JsonResponse
    {
        $this->authorizeAny($request, [P::QuestionsContribute, P::QuestionsManage, P::PapersSet, P::PapersReview]);
        $rows = Question::query()->where('course_id', $this->course($course)->id)->where('status', 'active')->get(['unit', 'type', 'marks', 'bloom_level']);

        return ApiResponse::data([
            'total' => $rows->count(),
            'by_unit' => $rows->countBy(fn (Question $q): string => (string) ($q->unit ?? '—'))->sortKeys()->all(),
            'by_type' => $rows->countBy(fn (Question $q): string => $q->type.' · '.((float) $q->marks))->sortKeys()->all(),
            'by_bloom' => $rows->countBy(fn (Question $q): string => $q->bloom_level ?? '—')->all(),
        ]);
    }

    public function storeQuestion(Request $request, string $course): JsonResponse
    {
        Gate::authorize(P::QuestionsContribute);
        $question = $this->bank->saveQuestion($this->course($course), $this->validatedQuestion($request), $this->user($request));

        return ApiResponse::data($this->question($question), 201);
    }

    public function updateQuestion(Request $request, Question $question): JsonResponse
    {
        abort_unless($this->bank->canEdit($this->user($request), $question), 403);
        $question = $this->bank->saveQuestion($question->course, $this->validatedQuestion($request), $this->user($request), $question);

        return ApiResponse::data($this->question($question));
    }

    /**
     * Retire a question (kept for papers that used it) or bring it back: `retired` true|false.
     */
    public function retireQuestion(Request $request, Question $question): JsonResponse
    {
        abort_unless($this->bank->canEdit($this->user($request), $question), 403);
        $retired = (bool) $request->validate(['retired' => ['required', 'boolean']])['retired'];
        $question->forceFill(['status' => $retired ? 'retired' : 'active'])->save();

        return ApiResponse::message($retired ? 'Question retired.' : 'Question restored.');
    }

    // ----- blueprints ------------------------------------------------------

    public function blueprints(Request $request, string $course): JsonResponse
    {
        $this->authorizeAny($request, [P::PapersSet, P::PapersReview]);
        $blueprints = PaperBlueprint::query()->where('course_id', $this->course($course)->id)->withCount('papers')->latest('id')->get();

        return ApiResponse::list($blueprints, fn (PaperBlueprint $b): array => $this->blueprint($b));
    }

    public function storeBlueprint(Request $request, string $course): JsonResponse
    {
        Gate::authorize(P::PapersSet);
        $blueprint = PaperBlueprint::query()->create([...$this->validatedBlueprint($request), 'course_id' => $this->course($course)->id]);

        return ApiResponse::data($this->blueprint($blueprint), 201);
    }

    public function updateBlueprint(Request $request, PaperBlueprint $blueprint): JsonResponse
    {
        Gate::authorize(P::PapersSet);
        $blueprint->update($this->validatedBlueprint($request));

        return ApiResponse::data($this->blueprint($blueprint));
    }

    public function destroyBlueprint(PaperBlueprint $blueprint): JsonResponse
    {
        Gate::authorize(P::PapersSet);

        return ApiResponse::deleteUnlessUsed($blueprint, ['papers' => 'question papers'], 'blueprint');
    }

    /**
     * Generate `sets` (1–4) papers, optionally for an exam in the timetable (`exam_schedule_id`).
     */
    public function generate(Request $request, PaperBlueprint $blueprint): JsonResponse
    {
        Gate::authorize(P::PapersSet);
        $validated = $request->validate(['sets' => ['required', 'integer', 'min:1', 'max:4'], 'exam_schedule_id' => ['nullable', 'string', 'size:26']]);
        $schedule = isset($validated['exam_schedule_id']) ? ExamSchedule::query()->where('ulid', $validated['exam_schedule_id'])->firstOrFail() : null;
        $papers = $this->bank->generate($blueprint, (int) $validated['sets'], $schedule);

        return ApiResponse::list($papers, fn (QuestionPaper $p): array => ['id' => $p->ulid, 'set_code' => $p->set_code]);
    }

    /**
     * Upcoming exams of the course (cycles not yet published) that papers can be generated for.
     */
    public function exams(Request $request, string $course): JsonResponse
    {
        Gate::authorize(P::PapersSet);
        $schedules = ExamSchedule::query()->with('cycle')->where('course_id', $this->course($course)->id)
            ->whereHas('cycle', fn ($query) => $query->where('status', '!=', 'published'))->orderBy('exam_date')->get();

        return ApiResponse::list($schedules, fn (ExamSchedule $s): array => ['id' => $s->ulid, 'cycle' => $s->cycle->name, 'date' => $s->exam_date->toDateString(), 'starts_at' => substr($s->starts_at, 0, 5)]);
    }

    // ----- papers ----------------------------------------------------------

    /**
     * Papers, newest first. Filters: `filter[course]`, `filter[status]`.
     */
    public function papers(Request $request): JsonResponse
    {
        $this->authorizeAny($request, [P::PapersSet, P::PapersReview, P::PapersSeal, P::PapersRelease, P::LogsView]);
        $filters = (array) $request->input('filter', []);

        $papers = QuestionPaper::query()->with(['course', 'blueprint', 'schedule.cycle', 'setter'])
            ->when($filters['course'] ?? null, fn ($query, $ulid) => $query->whereHas('course', fn ($q) => $q->where('ulid', $ulid)))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate(25);

        return ApiResponse::paginated($papers, fn (QuestionPaper $p): array => $this->paper($p, $this->user($request)));
    }

    public function show(Request $request, QuestionPaper $paper): JsonResponse
    {
        $this->authorizeAny($request, [P::PapersSet, P::PapersReview, P::PapersSeal, P::PapersRelease, P::LogsView]);

        return ApiResponse::data($this->paper($paper->load(['course', 'blueprint', 'schedule.cycle', 'setter', 'approver']), $this->user($request)));
    }

    /**
     * The paper's questions. See the service for who can open it and when; every opening is logged.
     */
    public function content(Request $request, QuestionPaper $paper): JsonResponse
    {
        return ApiResponse::data($this->bank->content($paper, $this->user($request), $request->ip()));
    }

    /**
     * Move a paper on: `submit`, `review` (`approve`, `remarks`), `seal`.
     */
    public function transition(Request $request, QuestionPaper $paper, string $action): JsonResponse
    {
        $user = $this->user($request);

        if ($action === 'submit') {
            Gate::authorize(P::PapersSet);
            $this->bank->submit($paper, $user);
        } elseif ($action === 'review') {
            Gate::authorize(P::PapersReview);
            $validated = $request->validate(['approve' => ['required', 'boolean'], 'remarks' => ['nullable', 'string', 'max:1000']]);
            $this->bank->review($paper, (bool) $validated['approve'], $validated['remarks'] ?? null, $user);
        } else {
            Gate::authorize(P::PapersSeal);
            $this->bank->seal($paper, $user, $request->ip());
        }

        return ApiResponse::data($this->paper($paper->refresh()->load(['course', 'blueprint', 'schedule.cycle', 'setter', 'approver']), $user));
    }

    public function destroy(QuestionPaper $paper): JsonResponse
    {
        Gate::authorize(P::PapersSet);

        if ($paper->status !== 'draft') {
            throw new BusinessRuleException('Only draft papers can be deleted.', 'INVALID_STATUS', 409);
        }

        $paper->delete();

        return ApiResponse::message('Paper deleted.');
    }

    public function logs(QuestionPaper $paper): JsonResponse
    {
        Gate::authorize(P::LogsView);

        return ApiResponse::list($paper->logs()->with('user')->get(), fn (PaperAccessLog $log): array => [
            'action' => $log->action, 'user' => $log->user?->name, 'ip' => $log->ip, 'at' => $log->created_at->toIso8601String(),
        ]);
    }

    // ----- helpers ---------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function question(Question $question): array
    {
        return [
            'id' => $question->ulid,
            'unit' => $question->unit,
            'type' => $question->type,
            'marks' => (float) $question->marks,
            'co_code' => $question->co_code,
            'bloom_level' => $question->bloom_level,
            'difficulty' => $question->difficulty,
            'status' => $question->status,
            ...$this->bank->body($question),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function blueprint(PaperBlueprint $blueprint): array
    {
        return [
            'id' => $blueprint->ulid,
            'name' => $blueprint->name,
            'duration_minutes' => $blueprint->duration_minutes,
            'avoid_recent_papers' => $blueprint->avoid_recent_papers,
            'sections' => $blueprint->sections,
            'total_marks' => $blueprint->totalMarks(),
            'papers' => $blueprint->papers_count ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paper(QuestionPaper $paper, User $user): array
    {
        [$opens, $closes] = $this->bank->window($paper);

        return [
            'id' => $paper->ulid,
            'set_code' => $paper->set_code,
            'status' => $paper->status,
            'remarks' => $paper->remarks,
            'course' => ['id' => $paper->course->ulid, 'code' => $paper->course->code, 'title' => $paper->course->title],
            'blueprint' => ['id' => $paper->blueprint->ulid, 'name' => $paper->blueprint->name, 'total_marks' => $paper->blueprint->totalMarks(), 'duration_minutes' => $paper->blueprint->duration_minutes],
            'exam' => $paper->schedule === null ? null : ['id' => $paper->schedule->ulid, 'cycle' => $paper->schedule->cycle->name, 'date' => $paper->schedule->exam_date->toDateString(), 'starts_at' => substr($paper->schedule->starts_at, 0, 5)],
            'release_window' => $opens === null ? null : ['opens' => $opens->toIso8601String(), 'closes' => $closes?->toIso8601String()],
            'set_by' => $paper->setter?->name,
            'approved_by' => $paper->relationLoaded('approver') ? $paper->approver?->name : null,
            'sealed_at' => $paper->sealed_at?->toIso8601String(),
            'created_at' => $paper->created_at?->toIso8601String(),
            'can' => [
                'view' => $paper->status !== 'sealed' ? $user->canAny([P::PapersSet, P::PapersReview]) : $user->can(P::PapersRelease),
                'submit' => $paper->status === 'draft' && $user->can(P::PapersSet),
                'review' => $paper->status === 'submitted' && $user->can(P::PapersReview) && $paper->created_by !== $user->id && $paper->submitted_by !== $user->id,
                'seal' => $paper->status === 'approved' && $user->can(P::PapersSeal),
                'delete' => $paper->status === 'draft' && $user->can(P::PapersSet),
                'logs' => $user->can(P::LogsView),
            ],
        ];
    }

    /**
     * @return array{unit?: int|null, type: string, marks: float, co_code?: string|null, bloom_level?: string|null, difficulty: string, text: string, options?: list<string>|null, answer?: string|null}
     */
    private function validatedQuestion(Request $request): array
    {
        /** @var array{unit?: int|null, type: string, marks: float, co_code?: string|null, bloom_level?: string|null, difficulty: string, text: string, options?: list<string>|null, answer?: string|null} */
        return $request->validate([
            'unit' => ['nullable', 'integer', 'min:1', 'max:20'],
            'type' => ['required', Rule::in(Question::TYPES)],
            'marks' => ['required', 'numeric', 'min:0.5', 'max:100'],
            'co_code' => ['nullable', 'string', 'max:10'],
            'bloom_level' => ['nullable', Rule::in(Question::BLOOM_LEVELS)],
            'difficulty' => ['required', Rule::in(Question::DIFFICULTIES)],
            'text' => ['required', 'string', 'max:10000'],
            'options' => ['nullable', 'array', 'max:6'],
            'options.*' => ['nullable', 'string', 'max:1000'],
            'answer' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedBlueprint(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:600'],
            'avoid_recent_papers' => ['required', 'integer', 'min:0', 'max:20'],
            'sections' => ['required', 'array', 'min:1', 'max:10'],
            'sections.*.name' => ['required', 'string', 'max:100'],
            'sections.*.type' => ['required', Rule::in(Question::TYPES)],
            'sections.*.marks' => ['required', 'numeric', 'min:0.5', 'max:100'],
            'sections.*.count' => ['required', 'integer', 'min:1', 'max:50'],
            'sections.*.attempt' => ['nullable', 'integer', 'min:1'],
            'sections.*.units' => ['nullable', 'array'],
            'sections.*.units.*' => ['integer', 'min:1', 'max:20'],
            'sections.*.bloom_levels' => ['nullable', 'array'],
            'sections.*.bloom_levels.*' => [Rule::in(Question::BLOOM_LEVELS)],
            'sections.*.difficulty' => ['nullable', Rule::in(Question::DIFFICULTIES)],
        ]);

        $validated['sections'] = array_map(fn (array $s): array => [
            'name' => $s['name'], 'type' => $s['type'], 'marks' => (float) $s['marks'], 'count' => (int) $s['count'],
            'attempt' => isset($s['attempt']) ? (int) $s['attempt'] : null, 'units' => $s['units'] ?? null,
            'bloom_levels' => $s['bloom_levels'] ?? null, 'difficulty' => $s['difficulty'] ?? null,
        ], $validated['sections']);

        foreach ($validated['sections'] as $section) {
            if ($section['attempt'] !== null && $section['attempt'] > $section['count']) {
                throw new BusinessRuleException('"'.$section['name'].'" asks students to attempt more questions than it has.', 'ATTEMPT_TOO_HIGH', 422);
            }
        }

        return $validated;
    }

    private function course(string $ulid): Course
    {
        return Course::query()->where('ulid', $ulid)->firstOrFail();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function authorizeAny(Request $request, array $permissions): void
    {
        abort_unless($this->user($request)->canAny($permissions), 403);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
