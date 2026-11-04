<?php

namespace App\Modules\Feedback\Http\Controllers;

use App\Models\User;
use App\Modules\Academics\Models\OfferingSection;
use App\Modules\Feedback\Authorization\FeedbackPermissions as P;
use App\Modules\Feedback\Models\Survey;
use App\Modules\Feedback\Models\SurveyQuestion;
use App\Modules\Feedback\Services\FeedbackService;
use App\Modules\Institution\Models\Term;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * @tags Feedback
 */
class SurveyController extends Controller
{
    public function __construct(private FeedbackService $feedback) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($this->user($request)->canAny([P::SurveysManage, P::ResultsView]), 403);
        $surveys = Survey::query()->with('term')->latest('id')->get();
        $counts = DB::table('survey_responses')->selectRaw('survey_id, count(*) as total')->groupBy('survey_id')->pluck('total', 'survey_id');

        return ApiResponse::list($surveys, fn (Survey $s): array => [...$this->survey($s), 'responses' => (int) ($counts[$s->id] ?? 0)]);
    }

    public function show(Request $request, Survey $survey): JsonResponse
    {
        abort_unless($this->user($request)->canAny([P::SurveysManage, P::ResultsView]), 403);

        return ApiResponse::data([...$this->survey($survey->load('term')), 'questions' => $survey->questions->map(fn (SurveyQuestion $q): array => $this->question($q))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->save($request, null);
    }

    public function update(Request $request, Survey $survey): JsonResponse
    {
        return $this->save($request, $survey);
    }

    private function save(Request $request, ?Survey $survey): JsonResponse
    {
        Gate::authorize(P::SurveysManage);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:1000'],
            'kind' => ['required', Rule::in(['course', 'general'])],
            'term_id' => ['nullable', 'string', 'size:26'],
            'audience' => ['required', Rule::in(['students', 'staff', 'all'])],
            'opens_on' => ['required', 'date'],
            'closes_on' => ['required', 'date', 'after_or_equal:opens_on'],
            'questions' => ['required', 'array', 'min:1', 'max:40'],
            'questions.*.text' => ['required', 'string', 'max:500'],
            'questions.*.type' => ['required', Rule::in(SurveyQuestion::TYPES)],
            'questions.*.options' => ['nullable', 'array', 'max:10'],
            'questions.*.options.*' => ['nullable', 'string', 'max:200'],
            'questions.*.required' => ['sometimes', 'boolean'],
        ]);
        $data = collect($validated)->except('questions')->all();
        $data['term_id'] = isset($validated['term_id']) ? Term::query()->where('ulid', $validated['term_id'])->value('id') : null;
        $data['audience'] = $validated['kind'] === 'course' ? 'students' : $validated['audience'];
        $created = $survey === null;
        $survey = $this->feedback->saveSurvey($data, $validated['questions'], $survey);

        return ApiResponse::data(['id' => $survey->ulid], $created ? 201 : 200);
    }

    /**
     * `open` or `close`.
     */
    public function transition(Request $request, Survey $survey, string $action): JsonResponse
    {
        Gate::authorize(P::SurveysManage);
        $this->feedback->setStatus($survey, $action === 'open' ? 'open' : 'closed');

        return ApiResponse::data($this->survey($survey->refresh()->load('term')));
    }

    /**
     * Results: overall and, for course feedback, per class (each hidden below the minimum responses).
     */
    public function results(Request $request, Survey $survey): JsonResponse
    {
        Gate::authorize(P::ResultsView);
        $classes = $survey->kind === 'course'
            ? OfferingSection::query()->with(['offering.course', 'section.batch', 'allocations.employee.user'])->whereIn('id', DB::table('survey_responses')->where('survey_id', $survey->id)->select('offering_section_id'))->get()
            : collect();

        return ApiResponse::data([
            ...$this->survey($survey->load('term')),
            'overall' => $this->feedback->results($survey),
            'classes' => $classes->map(fn (OfferingSection $c): array => [...$this->classData($c), 'results' => $this->feedback->results($survey, [$c->id])])->sortBy('course.code')->values(),
        ]);
    }

    // ----- self service ----------------------------------------------------

    /**
     * Surveys the signed-in user still has to answer.
     */
    public function pending(Request $request): JsonResponse
    {
        return ApiResponse::list(collect($this->feedback->pending($this->user($request))), fn (array $p): array => [
            ...$this->survey($p['survey']),
            'class' => $p['class'] === null ? null : $this->classData($p['class']->loadMissing(['offering.course', 'section.batch', 'allocations.employee.user'])),
            'questions' => $p['survey']->questions->map(fn (SurveyQuestion $q): array => $this->question($q))->values(),
        ]);
    }

    /**
     * Answer a survey: `class_id?` (course feedback), `answers` {question id: value}.
     */
    public function answer(Request $request, Survey $survey): JsonResponse
    {
        $validated = $request->validate(['class_id' => ['nullable', 'string', 'size:26'], 'answers' => ['required', 'array']]);
        $class = isset($validated['class_id']) ? OfferingSection::query()->where('ulid', $validated['class_id'])->firstOrFail() : null;
        $this->feedback->submit($survey->load('questions'), $this->user($request), $class, $validated['answers']);

        return ApiResponse::message('Thank you. Your answers are anonymous.');
    }

    /**
     * Course feedback for the classes the signed-in teacher teaches.
     */
    public function teacher(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $classes = OfferingSection::query()->with(['offering.course', 'section.batch', 'allocations.employee.user'])
            ->whereHas('allocations.employee', fn ($q) => $q->where('user_id', $user->id))->get();
        $surveys = Survey::query()->where('kind', 'course')->where('status', '!=', 'draft')->with('questions')->latest('id')->get();

        return ApiResponse::list($surveys, fn (Survey $s): array => [
            ...$this->survey($s),
            'classes' => $classes->filter(fn (OfferingSection $c): bool => $c->offering->term_id === $s->term_id)
                ->map(fn (OfferingSection $c): array => [...$this->classData($c), 'results' => $this->feedback->results($s, [$c->id])])->values(),
        ]);
    }

    // ----- presenters ------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function survey(Survey $s): array
    {
        return [
            'id' => $s->ulid, 'title' => $s->title, 'description' => $s->description, 'kind' => $s->kind, 'audience' => $s->audience,
            'term' => $s->term === null ? null : ['id' => $s->term->ulid, 'name' => $s->term->name],
            'opens_on' => $s->opens_on->toDateString(), 'closes_on' => $s->closes_on->toDateString(), 'status' => $s->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function question(SurveyQuestion $q): array
    {
        return ['id' => $q->ulid, 'text' => $q->text, 'type' => $q->type, 'options' => $q->options, 'required' => $q->required];
    }

    /**
     * @return array<string, mixed>
     */
    private function classData(OfferingSection $c): array
    {
        return [
            'id' => $c->ulid,
            'course' => ['code' => $c->offering->course->code, 'title' => $c->offering->course->title],
            'section' => $c->section->batch->name.' '.$c->section->name,
            'teachers' => $c->allocations->map(fn ($a) => $a->employee->user->name)->unique()->values()->all(),
        ];
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
