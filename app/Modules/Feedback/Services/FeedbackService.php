<?php

namespace App\Modules\Feedback\Services;

use App\Models\User;
use App\Modules\Academics\Models\OfferingSection;
use App\Modules\Assessment\Services\AssessmentService;
use App\Modules\Feedback\Models\Complaint;
use App\Modules\Feedback\Models\ComplaintCategory;
use App\Modules\Feedback\Models\ComplaintMessage;
use App\Modules\Feedback\Models\Survey;
use App\Modules\Feedback\Models\SurveyQuestion;
use App\Modules\HR\Models\Employee;
use App\Modules\Students\Models\Student;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Settings\SettingsRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Anonymous surveys (answers are stored without the person; completion is recorded separately)
 * and complaints.
 */
class FeedbackService
{
    public function __construct(private SettingsRepository $settings, private AssessmentService $assessment) {}

    // ----- surveys ---------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{text: string, type: string, options?: list<string>|null, required?: bool}>  $questions
     */
    public function saveSurvey(array $data, array $questions, ?Survey $survey = null): Survey
    {
        if ($survey !== null && $survey->status !== 'draft') {
            throw new BusinessRuleException('Only a draft survey can be changed.', 'SURVEY_LOCKED', 409);
        }

        if (($data['kind'] ?? null) === 'course' && empty($data['term_id'])) {
            throw new BusinessRuleException('Choose the term for course feedback.', 'TERM_REQUIRED', 422);
        }

        foreach ($questions as $question) {
            if ($question['type'] === 'choice' && count(array_filter($question['options'] ?? [], 'filled')) < 2) {
                throw new BusinessRuleException('Choice questions need at least two options.', 'OPTIONS_REQUIRED', 422);
            }
        }

        return DB::transaction(function () use ($data, $questions, $survey): Survey {
            $survey ??= new Survey;
            $survey->fill($data)->save();
            $survey->forceFill(['status' => $survey->status ?? 'draft'])->save();
            SurveyQuestion::query()->where('survey_id', $survey->id)->delete();

            foreach ($questions as $i => $q) {
                SurveyQuestion::query()->create(['survey_id' => $survey->id, 'position' => $i + 1, 'text' => $q['text'], 'type' => $q['type'], 'options' => $q['type'] === 'choice' ? array_values(array_filter($q['options'] ?? [], 'filled')) : null, 'required' => $q['required'] ?? true]);
            }

            return $survey;
        });
    }

    public function setStatus(Survey $survey, string $status): void
    {
        $allowed = ['draft' => ['open'], 'open' => ['closed'], 'closed' => ['open']];

        if (! in_array($status, $allowed[$survey->status] ?? [], true)) {
            throw new BusinessRuleException('This survey cannot be '.$status.' now.', 'INVALID_STATUS', 409);
        }

        if ($status === 'open' && ! $survey->questions()->exists()) {
            throw new BusinessRuleException('Add questions first.', 'NO_QUESTIONS', 422);
        }

        $survey->forceFill(['status' => $status])->save();
    }

    /**
     * Open surveys the user still has to answer: one entry per class for course feedback.
     *
     * @return list<array{survey: Survey, class: OfferingSection|null}>
     */
    public function pending(User $user): array
    {
        $student = Student::query()->where('user_id', $user->id)->first();
        $isStaff = Employee::query()->where('user_id', $user->id)->exists();
        $done = DB::table('survey_completions')->where('user_id', $user->id)->get()->map(fn ($c): string => $c->survey_id.':'.($c->offering_section_id ?? '-'))->all();
        $pending = [];

        foreach (Survey::query()->where('status', 'open')->whereDate('opens_on', '<=', today())->whereDate('closes_on', '>=', today())->orderBy('closes_on')->get() as $survey) {
            if (! $this->inAudience($survey, $student !== null, $isStaff)) {
                continue;
            }

            if ($survey->kind === 'course') {
                foreach ($student !== null && $survey->term_id !== null ? $this->assessment->studentClasses($student, $survey->term_id) : [] as $class) {
                    if (! in_array($survey->id.':'.$class->id, $done, true)) {
                        $pending[] = ['survey' => $survey, 'class' => $class];
                    }
                }
            } elseif (! in_array($survey->id.':-', $done, true)) {
                $pending[] = ['survey' => $survey, 'class' => null];
            }
        }

        return $pending;
    }

    /**
     * Stores the answers anonymously and records that the user has answered.
     *
     * @param  array<string, mixed>  $answers  question id (ulid) => rating (1–5), choice or text
     */
    public function submit(Survey $survey, User $user, ?OfferingSection $class, array $answers): void
    {
        $match = collect($this->pending($user))->first(fn (array $p): bool => $p['survey']->id === $survey->id && $p['class']?->id === $class?->id);

        if ($match === null) {
            throw new BusinessRuleException('This survey is not open for you, or you have already answered it.', 'NOT_PENDING', 409);
        }

        $rows = [];

        foreach ($survey->questions as $question) {
            $value = $answers[$question->ulid] ?? null;

            if (blank($value)) {
                if ($question->required) {
                    throw new BusinessRuleException('Answer every required question.', 'ANSWER_REQUIRED', 422, ['question' => $question->ulid]);
                }

                continue;
            }

            $rows[] = match ($question->type) {
                'rating' => ['survey_question_id' => $question->id, 'rating' => $this->rating($value), 'choice' => null, 'text' => null],
                'choice' => ['survey_question_id' => $question->id, 'rating' => null, 'choice' => in_array($value, $question->options ?? [], true) ? (string) $value : throw new BusinessRuleException('Pick one of the options.', 'CHOICE_INVALID', 422), 'text' => null],
                default => ['survey_question_id' => $question->id, 'rating' => null, 'choice' => null, 'text' => mb_substr((string) $value, 0, 2000)],
            };
        }

        DB::transaction(function () use ($survey, $user, $class, $rows): void {
            $responseId = DB::table('survey_responses')->insertGetId(['survey_id' => $survey->id, 'offering_section_id' => $class?->id, 'submitted_on' => today()->toDateString()]);
            DB::table('survey_answers')->insert(array_map(fn (array $row): array => [...$row, 'survey_response_id' => $responseId], $rows));
            DB::table('survey_completions')->insert(['survey_id' => $survey->id, 'user_id' => $user->id, 'offering_section_id' => $class?->id]);
        });
    }

    /**
     * Aggregated results, hidden below the minimum number of responses.
     *
     * @param  list<int>|null  $classIds  limit to these classes (null = whole survey)
     * @return array{responses: int, hidden: bool, minimum: int, questions: list<array<string, mixed>>}
     */
    public function results(Survey $survey, ?array $classIds = null): array
    {
        $minimum = (int) $this->settings->get('feedback', 'min_responses');
        $responses = DB::table('survey_responses')->where('survey_id', $survey->id)->when($classIds !== null, fn ($q) => $q->whereIn('offering_section_id', $classIds))->pluck('id');
        $hidden = $responses->count() < $minimum;
        $answers = $hidden ? collect() : DB::table('survey_answers')->whereIn('survey_response_id', $responses)->get()->groupBy('survey_question_id');

        return [
            'responses' => $responses->count(),
            'hidden' => $hidden,
            'minimum' => $minimum,
            'questions' => $survey->questions->map(function (SurveyQuestion $q) use ($answers): array {
                /** @var Collection<int, object> $rows */
                $rows = $answers->get($q->id, collect());

                return [
                    'id' => $q->ulid, 'text' => $q->text, 'type' => $q->type, 'answers' => $rows->count(),
                    'average' => $q->type === 'rating' && $rows->isNotEmpty() ? round((float) $rows->avg('rating'), 2) : null,
                    'distribution' => $q->type === 'rating' ? collect(range(1, 5))->mapWithKeys(fn (int $r): array => [$r => $rows->where('rating', $r)->count()])->all() : null,
                    'choices' => $q->type === 'choice' ? collect($q->options ?? [])->mapWithKeys(fn (string $o): array => [$o => $rows->where('choice', $o)->count()])->all() : null,
                    'comments' => $q->type === 'text' ? $rows->pluck('text')->filter()->shuffle()->values()->all() : null,
                ];
            })->values()->all(),
        ];
    }

    // ----- complaints ------------------------------------------------------

    public function raise(ComplaintCategory $category, string $subject, string $description, bool $anonymous): Complaint
    {
        $complaint = Complaint::query()->create(['complaint_category_id' => $category->id, 'subject' => $subject, 'description' => $description, 'is_anonymous' => $anonymous]);
        $complaint->forceFill(['status' => 'open', 'due_at' => now()->addHours($category->sla_hours)])->save();

        return $complaint;
    }

    public function canHandle(User $user, Complaint $complaint): bool
    {
        return $complaint->category->is_restricted ? $user->can('feedback.complaints.restricted') : $user->can('feedback.complaints.handle');
    }

    public function assign(Complaint $complaint, User $handler): void
    {
        $this->assertNotClosed($complaint);
        $complaint->forceFill(['assigned_to' => $handler->id, 'status' => $complaint->status === 'open' ? 'in_progress' : $complaint->status])->save();
    }

    /**
     * A reply (or an internal note) from staff; resolving also sets the status.
     */
    public function respond(Complaint $complaint, User $user, string $body, bool $internal, bool $resolve): void
    {
        $this->assertNotClosed($complaint);

        DB::transaction(function () use ($complaint, $user, $body, $internal, $resolve): void {
            ComplaintMessage::query()->create(['complaint_id' => $complaint->id, 'user_id' => $user->id, 'body' => $body, 'is_internal' => $internal && ! $resolve]);

            if ($resolve) {
                $complaint->forceFill(['status' => 'resolved', 'resolved_at' => now(), 'assigned_to' => $complaint->assigned_to ?? $user->id])->save();
            } elseif ($complaint->status === 'open' && ! $internal) {
                $complaint->forceFill(['status' => 'in_progress', 'assigned_to' => $complaint->assigned_to ?? $user->id])->save();
            }
        });
    }

    /**
     * The raiser adds a message; on a resolved complaint it reopens it.
     */
    public function followUp(Complaint $complaint, User $user, string $body): void
    {
        $this->assertNotClosed($complaint);

        DB::transaction(function () use ($complaint, $user, $body): void {
            ComplaintMessage::query()->create(['complaint_id' => $complaint->id, 'user_id' => $user->id, 'body' => $body, 'is_internal' => false]);

            if ($complaint->status === 'resolved') {
                $complaint->forceFill(['status' => 'in_progress', 'resolved_at' => null])->save();
            }
        });
    }

    /**
     * The raiser closes a resolved complaint with a 1–5 rating.
     */
    public function close(Complaint $complaint, int $rating): void
    {
        if ($complaint->status !== 'resolved') {
            throw new BusinessRuleException('Only a resolved complaint can be closed.', 'INVALID_STATUS', 409);
        }

        $complaint->forceFill(['status' => 'closed', 'rating' => $this->rating($rating)])->save();
    }

    private function rating(mixed $value): int
    {
        $rating = (int) $value;

        if ($rating < 1 || $rating > 5) {
            throw new BusinessRuleException('Ratings are from 1 to 5.', 'RATING_INVALID', 422);
        }

        return $rating;
    }

    private function inAudience(Survey $survey, bool $isStudent, bool $isStaff): bool
    {
        return match ($survey->audience) {
            'students' => $isStudent,
            'staff' => $isStaff,
            default => $isStudent || $isStaff,
        };
    }

    private function assertNotClosed(Complaint $complaint): void
    {
        if ($complaint->status === 'closed') {
            throw new BusinessRuleException('This complaint is closed.', 'COMPLAINT_CLOSED', 409);
        }
    }
}
