<?php

namespace Tests\Feature\Feedback;

use App\Models\User;
use App\Modules\Identity\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function activate(string $email): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        $user->forceFill(['password' => Hash::make(UserFactory::PASSWORD), 'status' => UserStatus::Active, 'email_verified_at' => now(), 'must_change_password' => false])->save();

        return $user;
    }

    public function test_anonymous_course_feedback_with_threshold_and_teacher_view(): void
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $this->setting($institution, 'feedback', ['min_responses' => 2]);

        $department = $this->api('POST', 'departments', $admin, ['code' => 'CSE', 'name' => 'CSE'])->json('data');
        $programme = $this->api('POST', 'programmes', $admin, ['department_id' => $department['id'], 'code' => 'BE-CSE', 'name' => 'B.E. CSE', 'level' => 'ug', 'duration_years' => 4])->json('data');
        $batch = $this->api('POST', 'batches', $admin, ['programme_id' => $programme['id'], 'admission_year' => 2026, 'sections' => ['A']])->json('data');
        $year = $this->api('POST', 'academic-years', $admin, ['name' => '2026-27', 'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonths(11)->toDateString()])->json('data');
        $term = $this->api('POST', 'terms', $admin, ['academic_year_id' => $year['id'], 'type' => 'odd', 'name' => 'Odd 2026', 'starts_on' => now()->subWeeks(3)->toDateString(), 'ends_on' => now()->addMonths(4)->toDateString()])->json('data');
        $this->api('POST', 'terms/'.$term['id'].'/make-current', $admin);
        $course = $this->api('POST', 'courses', $admin, ['department_id' => $department['id'], 'code' => '21CS51', 'title' => 'DBMS', 'type' => 'theory', 'credits' => 4])->json('data');
        $class = $this->api('POST', 'course-offerings', $admin, ['course_id' => $course['id'], 'term_id' => $term['id'], 'section_ids' => [$batch['sections'][0]['id']]])->json('data.sections.0.id');
        $kavya = $this->api('POST', 'employees', $admin, ['name' => 'Dr. Kavya', 'email' => 'kavya@example.edu', 'staff_type' => 'teaching', 'employment_type' => 'permanent', 'joined_on' => '2020-06-01', 'send_invite' => false])->json('data');
        $this->api('POST', 'offering-sections/'.$class.'/allocations', $admin, ['employee_id' => $kavya['id'], 'component' => 'theory', 'weekly_hours' => 4]);
        $teacher = $this->activate('kavya@example.edu');

        $students = [];
        foreach (['asha', 'bala'] as $name) {
            $this->api('POST', 'students', $admin, ['name' => ucfirst($name), 'email' => "{$name}@example.edu", 'programme_id' => $programme['id'], 'admission_type' => 'cet', 'admitted_on' => now()->subWeeks(3)->toDateString(), 'batch_id' => $batch['id'], 'section_id' => $batch['sections'][0]['id'], 'send_invite' => false]);
            $students[$name] = $this->activate("{$name}@example.edu");
            $this->api('POST', 'me/term-registration', $students[$name], ['elective_offering_ids' => []])->assertOk();
        }

        $survey = $this->api('POST', 'surveys', $admin, [
            'title' => 'Mid-semester course feedback', 'kind' => 'course', 'term_id' => $term['id'], 'audience' => 'students',
            'opens_on' => today()->toDateString(), 'closes_on' => today()->addWeek()->toDateString(),
            'questions' => [['text' => 'The teacher explains clearly', 'type' => 'rating'], ['text' => 'Pace of the course', 'type' => 'choice', 'options' => ['Too slow', 'Right', 'Too fast']], ['text' => 'Suggestions', 'type' => 'text', 'required' => false]],
        ])->assertCreated()->json('data.id');
        $this->api('GET', 'me/surveys', $students['asha'])->assertJsonCount(0, 'data');
        $this->api('POST', 'surveys/'.$survey.'/open', $admin)->assertOk();

        $pending = $this->api('GET', 'me/surveys', $students['asha'])->assertJsonCount(1, 'data')->assertJsonPath('data.0.class.teachers.0', 'Dr. Kavya')->json('data.0');
        [$rating, $pace, $text] = array_column($pending['questions'], 'id');

        $this->api('POST', 'me/surveys/'.$survey.'/answers', $students['asha'], ['class_id' => $class, 'answers' => [$rating => 6, $pace => 'Right']])->assertStatus(422)->assertJsonPath('code', 'RATING_INVALID');
        $this->api('POST', 'me/surveys/'.$survey.'/answers', $students['asha'], ['class_id' => $class, 'answers' => [$pace => 'Right']])->assertStatus(422)->assertJsonPath('code', 'ANSWER_REQUIRED');
        $this->api('POST', 'me/surveys/'.$survey.'/answers', $students['asha'], ['class_id' => $class, 'answers' => [$rating => 5, $pace => 'Right', $text => 'More examples please']])->assertOk();
        $this->api('POST', 'me/surveys/'.$survey.'/answers', $students['asha'], ['class_id' => $class, 'answers' => [$rating => 5, $pace => 'Right']])->assertStatus(409)->assertJsonPath('code', 'NOT_PENDING');

        // Answers carry no person; below the threshold the teacher sees nothing.
        $this->assertFalse(collect(DB::getSchemaBuilder()->getColumnListing('survey_responses'))->contains('user_id'));
        $this->api('GET', 'me/teaching-feedback', $teacher)->assertOk()->assertJsonPath('data.0.classes.0.results.hidden', true)->assertJsonPath('data.0.classes.0.results.questions.0.average', null);

        $this->api('POST', 'me/surveys/'.$survey.'/answers', $students['bala'], ['class_id' => $class, 'answers' => [$rating => 3, $pace => 'Too fast']])->assertOk();
        $this->api('GET', 'me/teaching-feedback', $teacher)->assertJsonPath('data.0.classes.0.results.hidden', false)->assertJsonPath('data.0.classes.0.results.questions.0.average', 4)
            ->assertJsonPath('data.0.classes.0.results.questions.1.choices.Too fast', 1)->assertJsonPath('data.0.classes.0.results.questions.2.comments.0', 'More examples please');
        $this->api('GET', 'surveys/'.$survey.'/results', $admin)->assertOk()->assertJsonPath('data.overall.responses', 2)->assertJsonPath('data.classes.0.course.code', '21CS51');
        $this->api('GET', 'surveys/'.$survey.'/results', $teacher)->assertForbidden();
    }

    public function test_complaints_with_restricted_categories_anonymity_and_rating(): void
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $office = $this->member($institution, ['admin_staff'], ['email' => 'office@example.edu']);
        $student = $this->member($institution, ['student'], ['email' => 'stu@example.edu', 'name' => 'Asha']);

        $hostel = $this->api('POST', 'complaint-categories', $admin, ['name' => 'Infrastructure', 'sla_hours' => 48, 'is_restricted' => false])->assertCreated()->json('data.id');
        $icc = $this->api('POST', 'complaint-categories', $admin, ['name' => 'Harassment (ICC)', 'sla_hours' => 24, 'is_restricted' => true])->json('data.id');
        $this->api('POST', 'complaint-categories', $office, ['name' => 'x', 'sla_hours' => 1, 'is_restricted' => false])->assertForbidden();

        $fan = $this->api('POST', 'me/complaints', $student, ['category_id' => $hostel, 'subject' => 'Fans not working in LH-201', 'description' => 'Two of four fans have not worked for a week.', 'anonymous' => true])->assertCreated()->json('data.id');
        $this->api('POST', 'me/complaints', $student, ['category_id' => $icc, 'subject' => 'Restricted matter', 'description' => 'Details'])->assertCreated();

        // Office handles general complaints only; the raiser's name is hidden when anonymous.
        $this->api('GET', 'complaints', $office)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.raised_by', null)->assertJsonPath('data.0.subject', 'Fans not working in LH-201');
        $this->api('GET', 'complaints', $admin)->assertJsonCount(2, 'data');
        $this->api('GET', 'complaints', $student)->assertForbidden();

        $this->api('POST', 'complaints/'.$fan.'/assign', $office)->assertOk();
        $this->api('POST', 'complaints/'.$fan.'/respond', $office, ['body' => 'Electrician informed', 'internal' => true])->assertOk();
        $this->api('POST', 'complaints/'.$fan.'/respond', $office, ['body' => 'Fans repaired today.', 'resolve' => true])->assertOk();

        $this->api('GET', 'complaints/'.$fan, $student)->assertOk()->assertJsonPath('data.status', 'resolved')->assertJsonCount(1, 'data.messages')->assertJsonPath('data.messages.0.body', 'Fans repaired today.');
        $this->api('GET', 'complaints/'.$fan, $office)->assertJsonCount(2, 'data.messages');

        $this->api('POST', 'me/complaints/'.$fan.'/messages', $student, ['body' => 'One fan still makes noise'])->assertOk();
        $this->api('GET', 'me/complaints', $student)->assertJsonPath('data.1.status', 'in_progress');
        $this->api('POST', 'me/complaints/'.$fan.'/close', $student, ['rating' => 4])->assertStatus(409);
        $this->api('POST', 'complaints/'.$fan.'/respond', $office, ['body' => 'Fan replaced.', 'resolve' => true])->assertOk();
        $this->api('POST', 'me/complaints/'.$fan.'/close', $student, ['rating' => 4])->assertOk();
        $this->api('POST', 'complaints/'.$fan.'/respond', $office, ['body' => 'x'])->assertStatus(409)->assertJsonPath('code', 'COMPLAINT_CLOSED');
    }
}
