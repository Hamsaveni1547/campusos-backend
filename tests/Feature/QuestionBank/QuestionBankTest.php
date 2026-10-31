<?php

namespace Tests\Feature\QuestionBank;

use App\Models\User;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\QuestionBank\Models\Question;
use App\Modules\QuestionBank\Models\QuestionPaper;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class QuestionBankTest extends TestCase
{
    use RefreshDatabase;

    private function activate(string $email): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        $user->forceFill(['password' => Hash::make(UserFactory::PASSWORD), 'status' => UserStatus::Active, 'email_verified_at' => now(), 'must_change_password' => false])->save();

        return $user;
    }

    /**
     * DBMS with 6 short (2 marks) and 3 long (10 marks) questions added by Kavya (faculty).
     *
     * @return array<string, mixed>
     */
    private function bank(): array
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $department = $this->api('POST', 'departments', $admin, ['code' => 'CSE', 'name' => 'Computer Science'])->json('data');
        $course = $this->api('POST', 'courses', $admin, ['department_id' => $department['id'], 'code' => '21CS51', 'title' => 'DBMS', 'type' => 'theory', 'credits' => 4])->json('data.id');
        $this->api('POST', 'employees', $admin, ['name' => 'Dr. Kavya', 'email' => 'kavya@example.edu', 'staff_type' => 'teaching', 'employment_type' => 'permanent', 'joined_on' => '2020-06-01', 'send_invite' => false]);
        $kavya = $this->activate('kavya@example.edu');

        foreach (range(1, 6) as $n) {
            $this->api('POST', 'courses/'.$course.'/questions', $kavya, ['unit' => $n <= 3 ? 1 : 2, 'type' => 'short', 'marks' => 2, 'difficulty' => 'easy', 'bloom_level' => 'remember', 'co_code' => 'CO1', 'text' => "Define term {$n}.", 'answer' => "Term {$n} is…"])->assertCreated();
        }
        foreach (range(1, 3) as $n) {
            $this->api('POST', 'courses/'.$course.'/questions', $kavya, ['unit' => 1, 'type' => 'long', 'marks' => 10, 'difficulty' => 'medium', 'bloom_level' => 'apply', 'text' => "Design schema {$n}."])->assertCreated();
        }

        return ['institution' => $institution, 'admin' => $admin, 'course' => $course, 'kavya' => $kavya];
    }

    public function test_questions_are_encrypted_deduplicated_and_owned(): void
    {
        ['institution' => $institution, 'admin' => $admin, 'course' => $course, 'kavya' => $kavya] = $this->bank();

        $this->assertStringNotContainsString('Define term', (string) DB::table('questions')->value('encrypted_body'));
        $list = $this->api('GET', 'courses/'.$course.'/questions?filter[type]=short', $kavya)->assertOk()->assertJsonCount(6, 'data');
        $this->assertSame('Define term 1.', $list->json('data.0.text'));
        $this->assertTrue($list->json('data.0.can_edit'));

        $this->api('POST', 'courses/'.$course.'/questions', $kavya, ['type' => 'short', 'marks' => 2, 'difficulty' => 'easy', 'text' => '  define   TERM 1. '])->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_QUESTION');
        $this->api('POST', 'courses/'.$course.'/questions', $kavya, ['type' => 'mcq', 'marks' => 1, 'difficulty' => 'easy', 'text' => 'Pick one', 'options' => ['A']])->assertStatus(422)->assertJsonPath('code', 'OPTIONS_REQUIRED');

        $this->api('POST', 'employees', $admin, ['name' => 'Dr. Ravi', 'email' => 'ravi@example.edu', 'staff_type' => 'teaching', 'employment_type' => 'permanent', 'joined_on' => '2020-06-01', 'send_invite' => false]);
        $ravi = $this->activate('ravi@example.edu');
        $id = $list->json('data.0.id');
        $this->api('PATCH', 'questions/'.$id, $ravi, ['type' => 'short', 'marks' => 2, 'difficulty' => 'easy', 'text' => 'Changed'])->assertForbidden();
        $this->api('POST', 'questions/'.$id.'/retire', $kavya, ['retired' => true])->assertOk();
        $this->api('GET', 'courses/'.$course.'/question-coverage', $kavya)->assertOk()->assertJsonPath('data.total', 8)->assertJsonPath('data.by_unit.2', 3);

        $student = $this->member($institution, ['student'], ['email' => 'stu@example.edu']);
        $this->api('GET', 'courses/'.$course.'/questions', $student)->assertForbidden();
    }

    public function test_blueprint_generation_review_seal_and_time_locked_release(): void
    {
        $this->travelTo(now()->setTime(8, 0));
        ['institution' => $institution, 'admin' => $admin, 'course' => $course, 'kavya' => $kavya] = $this->bank();

        $sections = [['name' => 'Part A', 'type' => 'short', 'marks' => 2, 'count' => 2], ['name' => 'Part B', 'type' => 'long', 'marks' => 10, 'count' => 3, 'attempt' => 2]];
        $this->api('POST', 'courses/'.$course.'/paper-blueprints', $kavya, ['name' => 'x', 'duration_minutes' => 180, 'avoid_recent_papers' => 2, 'sections' => [['name' => 'Bad', 'type' => 'long', 'marks' => 10, 'count' => 1, 'attempt' => 2]]])->assertStatus(422)->assertJsonPath('code', 'ATTEMPT_TOO_HIGH');
        $blueprint = $this->api('POST', 'courses/'.$course.'/paper-blueprints', $kavya, ['name' => 'End-term', 'duration_minutes' => 180, 'avoid_recent_papers' => 2, 'sections' => $sections])
            ->assertCreated()->assertJsonPath('data.total_marks', 24)->json('data.id');

        $tooBig = $this->api('POST', 'courses/'.$course.'/paper-blueprints', $kavya, ['name' => 'Too big', 'duration_minutes' => 180, 'avoid_recent_papers' => 0, 'sections' => [['name' => 'Essays', 'type' => 'long', 'marks' => 10, 'count' => 4]]])->json('data.id');
        $this->api('POST', 'paper-blueprints/'.$tooBig.'/generate', $kavya, ['sets' => 1])->assertStatus(422)->assertJsonPath('code', 'NOT_ENOUGH_QUESTIONS')->assertJsonPath('context.section', 'Essays');
        $sets = $this->api('POST', 'paper-blueprints/'.$blueprint.'/generate', $kavya, ['sets' => 2])->assertOk()->json('data');
        $this->assertSame(['A', 'B'], array_column($sets, 'set_code'));

        // Two sets of Part A share no questions while the bank allows it.
        $partA = fn (string $ulid) => DB::table('question_paper_items')->where('question_paper_id', QuestionPaper::query()->where('ulid', $ulid)->value('id'))->where('section', 0)->pluck('question_id')->all();
        $this->assertSame([], array_intersect($partA($sets[0]['id']), $partA($sets[1]['id'])));

        $paper = $sets[0]['id'];
        $this->api('GET', 'question-papers/'.$paper.'/content', $kavya)->assertOk()->assertJsonPath('data.answers', true)->assertJsonCount(3, 'data.sections.1.questions');
        $this->api('POST', 'question-papers/'.$paper.'/submit', $kavya)->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->api('POST', 'question-papers/'.$paper.'/review', $kavya, ['approve' => true])->assertForbidden();

        $hod = $this->member($institution, ['hod'], ['email' => 'hod@example.edu']);
        $this->api('POST', 'question-papers/'.$paper.'/review', $hod, ['approve' => false])->assertStatus(422)->assertJsonPath('code', 'REMARKS_REQUIRED');
        $this->api('POST', 'question-papers/'.$paper.'/review', $hod, ['approve' => true])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->api('POST', 'question-papers/'.$paper.'/seal', $hod)->assertForbidden();
        $this->api('POST', 'question-papers/'.$paper.'/seal', $admin)->assertOk()->assertJsonPath('data.status', 'sealed');

        $row = QuestionPaper::query()->where('ulid', $paper)->firstOrFail();
        $this->assertStringNotContainsString('Define term', (string) $row->encrypted_payload);
        $this->api('DELETE', 'question-papers/'.$paper, $kavya)->assertStatus(409);

        // A question in a sealed paper cannot be reworded.
        $sealedQuestion = Question::query()->whereKey(DB::table('question_paper_items')->where('question_paper_id', $row->id)->value('question_id'))->firstOrFail();
        $this->api('PATCH', 'questions/'.$sealedQuestion->ulid, $kavya, ['type' => $sealedQuestion->type, 'marks' => (float) $sealedQuestion->marks, 'difficulty' => $sealedQuestion->difficulty, 'text' => 'Reworded'])->assertStatus(409)->assertJsonPath('code', 'QUESTION_IN_USE');

        // Release needs an exam in the timetable and only opens in the window.
        $this->api('GET', 'question-papers/'.$paper.'/content', $kavya)->assertForbidden();
        $this->api('GET', 'question-papers/'.$paper.'/content', $admin)->assertStatus(403)->assertJsonPath('code', 'OUTSIDE_RELEASE_WINDOW');

        $term = $this->api('POST', 'terms', $admin, ['academic_year_id' => $this->api('POST', 'academic-years', $admin, ['name' => '2026-27', 'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonths(11)->toDateString()])->json('data.id'), 'type' => 'odd', 'name' => 'Odd 2026', 'starts_on' => now()->subWeeks(3)->toDateString(), 'ends_on' => now()->addMonths(4)->toDateString()])->json('data.id');
        $cycle = $this->api('POST', 'exam-cycles', $admin, ['name' => 'Nov 2026', 'type' => 'regular', 'term_id' => $term])->json('data.id');
        $exam = $this->api('POST', 'exam-cycles/'.$cycle.'/schedules', $admin, ['course_id' => $course, 'exam_date' => now()->toDateString(), 'starts_at' => '10:00', 'ends_at' => '13:00', 'max_marks' => 60])->json('data.id');
        $this->api('GET', 'courses/'.$course.'/upcoming-exams', $kavya)->assertOk()->assertJsonPath('data.0.id', $exam)->assertJsonPath('data.0.starts_at', '10:00');
        $later = $this->api('POST', 'paper-blueprints/'.$blueprint.'/generate', $kavya, ['sets' => 1, 'exam_schedule_id' => $exam])->json('data.0.id');
        $this->api('POST', 'question-papers/'.$later.'/submit', $kavya);
        $this->api('POST', 'question-papers/'.$later.'/review', $hod, ['approve' => true]);
        $this->api('POST', 'question-papers/'.$later.'/seal', $admin);

        $this->travelTo(now()->setTime(9, 20));
        $this->api('GET', 'question-papers/'.$later.'/content', $admin)->assertStatus(403)->assertJsonPath('code', 'OUTSIDE_RELEASE_WINDOW');
        $this->travelTo(now()->setTime(9, 45));
        $released = $this->api('GET', 'question-papers/'.$later.'/content', $admin)->assertOk()->assertJsonPath('data.answers', false);
        $this->assertNull($released->json('data.sections.0.questions.0.answer'));
        $this->assertStringStartsWith('Define term', $released->json('data.sections.0.questions.0.text'));

        $this->api('GET', 'question-papers/'.$later.'/access-logs', $kavya)->assertForbidden();
        $this->assertSame(['released', 'release_refused', 'sealed'], array_column($this->api('GET', 'question-papers/'.$later.'/access-logs', $admin)->assertOk()->json('data'), 'action'));
        $this->assertSame(['release_refused', 'sealed', 'viewed'], array_values(array_unique(array_column($this->api('GET', 'question-papers/'.$paper.'/access-logs', $admin)->json('data'), 'action'))));
    }
}
