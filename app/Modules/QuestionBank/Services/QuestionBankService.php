<?php

namespace App\Modules\QuestionBank\Services;

use App\Models\User;
use App\Modules\Academics\Models\Course;
use App\Modules\Exams\Models\ExamSchedule;
use App\Modules\QuestionBank\Authorization\QuestionBankPermissions;
use App\Modules\QuestionBank\Models\PaperAccessLog;
use App\Modules\QuestionBank\Models\PaperBlueprint;
use App\Modules\QuestionBank\Models\Question;
use App\Modules\QuestionBank\Models\QuestionPaper;
use App\Modules\QuestionBank\Models\QuestionPaperItem;
use App\Modules\QuestionBank\Support\PaperCrypto;
use App\Modules\QuestionBank\Support\PaperGenerator;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Settings\SettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Question bank, paper generation, review, sealing and time-locked release.
 */
class QuestionBankService
{
    public function __construct(private PaperCrypto $crypto, private SettingsRepository $settings) {}

    // ----- questions -------------------------------------------------------

    /**
     * @param  array{unit?: int|null, type: string, marks: float, co_code?: string|null, bloom_level?: string|null, difficulty: string, text: string, options?: list<string>|null, answer?: string|null}  $data
     */
    public function saveQuestion(Course $course, array $data, User $user, ?Question $question = null): Question
    {
        if ($data['type'] === 'mcq' && count(array_filter($data['options'] ?? [], 'filled')) < 2) {
            throw new BusinessRuleException('A multiple-choice question needs at least two options.', 'OPTIONS_REQUIRED', 422);
        }

        $body = ['text' => trim($data['text']), 'options' => $data['type'] === 'mcq' ? array_values(array_filter($data['options'] ?? [], 'filled')) : null, 'answer' => $data['answer'] ?? null];
        $hash = hash('sha256', mb_strtolower(preg_replace('/\s+/', ' ', $body['text']) ?? $body['text']));

        if ($question !== null && $this->isSealed($question) && ($hash !== $question->body_hash || (float) $data['marks'] !== (float) $question->marks)) {
            throw new BusinessRuleException('This question is in a sealed paper. Add a new question instead.', 'QUESTION_IN_USE', 409);
        }

        $duplicate = Question::query()->where('course_id', $course->id)->where('body_hash', $hash)->when($question, fn ($q) => $q->whereKeyNot($question?->id))->exists();

        if ($duplicate) {
            throw new BusinessRuleException('The same question is already in the bank.', 'DUPLICATE_QUESTION', 409);
        }

        $question ??= new Question(['course_id' => $course->id]);
        $question->fill(collect($data)->only(['unit', 'type', 'marks', 'co_code', 'bloom_level', 'difficulty'])->all());
        $question->forceFill(['encrypted_body' => $this->crypto->encrypt((string) json_encode($body)), 'body_hash' => $hash])->save();

        return $question;
    }

    /**
     * @return array{text: string, options: list<string>|null, answer: string|null}
     */
    public function body(Question $question): array
    {
        /** @var array{text: string, options: list<string>|null, answer: string|null} */
        return json_decode($this->crypto->decrypt($question->encrypted_body), true);
    }

    public function canEdit(User $user, Question $question): bool
    {
        return $user->can(QuestionBankPermissions::QuestionsManage) || ($user->can(QuestionBankPermissions::QuestionsContribute) && $question->created_by === $user->id);
    }

    public function isSealed(Question $question): bool
    {
        return QuestionPaperItem::query()->where('question_id', $question->id)->whereIn('question_paper_id', QuestionPaper::query()->where('status', 'sealed')->select('id'))->exists();
    }

    // ----- papers ----------------------------------------------------------

    /**
     * Generates 1–4 sets (A, B…) from a blueprint, preferring questions not used in recent papers or other sets.
     *
     * @return Collection<int, QuestionPaper>
     */
    public function generate(PaperBlueprint $blueprint, int $sets, ?ExamSchedule $schedule): Collection
    {
        if ($schedule !== null && $schedule->course_id !== $blueprint->course_id) {
            throw new BusinessRuleException('That exam is for another course.', 'SCHEDULE_MISMATCH', 422);
        }

        $bank = Question::query()->where('course_id', $blueprint->course_id)->where('status', 'active')->get()
            ->map(fn (Question $q): array => ['id' => $q->id, 'type' => $q->type, 'marks' => (float) $q->marks, 'unit' => $q->unit, 'bloom_level' => $q->bloom_level, 'difficulty' => $q->difficulty])->values()->all();
        $recentPapers = QuestionPaper::query()->where('course_id', $blueprint->course_id)->whereIn('status', ['approved', 'sealed'])->latest('id')->limit($blueprint->avoid_recent_papers)->pluck('id');
        $avoid = QuestionPaperItem::query()->whereIn('question_paper_id', $recentPapers)->pluck('question_id')->all();
        $sections = array_map(fn (array $s): array => ['type' => $s['type'], 'marks' => (float) $s['marks'], 'count' => (int) $s['count'], 'units' => $s['units'] ?? null, 'bloom_levels' => $s['bloom_levels'] ?? null, 'difficulty' => $s['difficulty'] ?? null], $blueprint->sections);

        $generated = [];
        $inOtherSets = [];

        for ($set = 0; $set < $sets; $set++) {
            $seed = random_int(1, 2_000_000_000);
            $result = PaperGenerator::generate($sections, $bank, [...$avoid, ...$inOtherSets], $seed);

            if ($result['short'] !== null) {
                $name = $blueprint->sections[$result['short']]['name'];
                throw new BusinessRuleException('Not enough matching questions in the bank for "'.$name.'".', 'NOT_ENOUGH_QUESTIONS', 422, ['section' => $name]);
            }

            $generated[] = ['seed' => $seed, 'sections' => $result['sections']];

            foreach ($result['sections'] as $ids) {
                $inOtherSets = [...$inOtherSets, ...$ids];
            }
        }

        return DB::transaction(function () use ($blueprint, $schedule, $generated): Collection {
            $used = QuestionPaper::query()->where('paper_blueprint_id', $blueprint->id)->pluck('set_code')->all();
            $codes = array_values(array_diff(range('A', 'Z'), $used));

            return collect($generated)->map(function (array $set, int $index) use ($blueprint, $schedule, $codes): QuestionPaper {
                $paper = QuestionPaper::query()->create(['paper_blueprint_id' => $blueprint->id, 'course_id' => $blueprint->course_id, 'exam_schedule_id' => $schedule?->id, 'set_code' => $codes[$index], 'seed' => $set['seed']]);

                foreach ($set['sections'] as $section => $ids) {
                    foreach ($ids as $position => $id) {
                        QuestionPaperItem::query()->create(['question_paper_id' => $paper->id, 'question_id' => $id, 'section' => $section, 'position' => $position + 1]);
                    }
                }

                return $paper;
            });
        });
    }

    public function submit(QuestionPaper $paper, User $user): void
    {
        $this->assertStatus($paper, 'draft');
        $paper->forceFill(['status' => 'submitted', 'submitted_by' => $user->id])->save();
    }

    public function review(QuestionPaper $paper, bool $approve, ?string $remarks, User $user): void
    {
        $this->assertStatus($paper, 'submitted');

        if ($paper->created_by === $user->id || $paper->submitted_by === $user->id) {
            throw new BusinessRuleException('The paper setter cannot approve their own paper.', 'SAME_PERSON', 409);
        }

        if (! $approve && blank($remarks)) {
            throw new BusinessRuleException('Say what needs to change.', 'REMARKS_REQUIRED', 422);
        }

        $paper->forceFill($approve
            ? ['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now(), 'remarks' => $remarks]
            : ['status' => 'draft', 'remarks' => $remarks])->save();
    }

    /**
     * Seals an approved paper: its full content is encrypted with a new data key and becomes immutable.
     */
    public function seal(QuestionPaper $paper, User $user, ?string $ip): void
    {
        $this->assertStatus($paper, 'approved');
        $sealed = $this->crypto->seal((string) json_encode($this->build($paper, withAnswers: true)));

        DB::transaction(function () use ($paper, $sealed, $user, $ip): void {
            $paper->forceFill(['status' => 'sealed', 'sealed_by' => $user->id, 'sealed_at' => now(), 'encrypted_payload' => $sealed['payload'], 'wrapped_key' => $sealed['wrapped_key'], 'payload_hash' => $sealed['hash']])->save();
            $this->log($paper, $user, 'sealed', $ip);
        });
    }

    /**
     * Paper content. Before sealing, setters and reviewers see it with answers. A sealed paper opens only for
     * release holders inside the window before its exam, without answers. Every opening is logged.
     *
     * @return array{sections: list<array<string, mixed>>, answers: bool}
     */
    public function content(QuestionPaper $paper, User $user, ?string $ip): array
    {
        if ($paper->status !== 'sealed') {
            abort_unless($user->canAny([QuestionBankPermissions::PapersSet, QuestionBankPermissions::PapersReview]), 403);
            $this->log($paper, $user, 'viewed', $ip);

            return ['sections' => $this->build($paper, withAnswers: true), 'answers' => true];
        }

        abort_unless($user->can(QuestionBankPermissions::PapersRelease), 403);
        [$opens, $closes] = $this->window($paper);

        if ($opens === null || now()->lt($opens) || now()->gt($closes)) {
            $this->log($paper, $user, 'release_refused', $ip);

            throw new BusinessRuleException($opens === null ? 'This paper is not linked to an exam in the timetable.' : 'This paper can be opened from '.$opens->format('d M Y H:i').' to '.$closes?->format('H:i').'.', 'OUTSIDE_RELEASE_WINDOW', 403);
        }

        /** @var list<array<string, mixed>> $sections */
        $sections = json_decode($this->crypto->open((string) $paper->encrypted_payload, (string) $paper->wrapped_key, (string) $paper->payload_hash), true);
        $this->log($paper, $user, 'released', $ip);

        return ['sections' => array_map(fn (array $s): array => [...$s, 'questions' => array_map(fn (array $q): array => [...$q, 'answer' => null], $s['questions'])], $sections), 'answers' => false];
    }

    /**
     * @return array{0: Carbon|null, 1: Carbon|null}
     */
    public function window(QuestionPaper $paper): array
    {
        $schedule = $paper->schedule;

        if ($schedule === null) {
            return [null, null];
        }

        $date = $schedule->exam_date->toDateString();
        $starts = Carbon::parse($date.' '.$schedule->starts_at);

        return [$starts->copy()->subMinutes((int) $this->settings->get('qbank', 'release_minutes_before')), Carbon::parse($date.' '.$schedule->ends_at)];
    }

    // ----- internals -------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function build(QuestionPaper $paper, bool $withAnswers): array
    {
        $items = $paper->items()->with('question')->get()->groupBy('section');

        return collect($paper->blueprint->sections)->map(fn (array $section, int $index): array => [
            'name' => $section['name'],
            'marks' => (float) $section['marks'],
            'count' => (int) $section['count'],
            'attempt' => $section['attempt'] ?? null,
            'questions' => $items->get($index, collect())->map(function (QuestionPaperItem $item) use ($withAnswers): array {
                $body = $this->body($item->question);

                return [
                    'number' => $item->position,
                    'text' => $body['text'],
                    'options' => $body['options'],
                    'answer' => $withAnswers ? $body['answer'] : null,
                    'marks' => (float) $item->question->marks,
                    'co_code' => $item->question->co_code,
                    'bloom_level' => $item->question->bloom_level,
                ];
            })->values()->all(),
        ])->values()->all();
    }

    private function log(QuestionPaper $paper, User $user, string $action, ?string $ip): void
    {
        PaperAccessLog::query()->create(['question_paper_id' => $paper->id, 'user_id' => $user->id, 'action' => $action, 'ip' => $ip]);
    }

    private function assertStatus(QuestionPaper $paper, string $status): void
    {
        if ($paper->status !== $status) {
            throw new BusinessRuleException('This paper is not at that stage.', 'INVALID_STATUS', 409);
        }
    }
}
