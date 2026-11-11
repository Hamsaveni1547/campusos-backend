<?php

namespace Tests\Feature\Exams;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamCycleApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_examiner_cycles_wait_for_principal_approval(): void
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $examiner = $this->member($institution, ['examiner'], ['email' => 'coe@example.edu']);
        $principal = $this->member($institution, ['institution_head'], ['email' => 'principal@example.edu']);

        $year = $this->api('POST', 'academic-years', $admin, ['name' => '2026-27', 'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonths(11)->toDateString()])->json('data');
        $term = $this->api('POST', 'terms', $admin, ['academic_year_id' => $year['id'], 'type' => 'odd', 'name' => 'Odd 2026', 'starts_on' => now()->subWeeks(3)->toDateString(), 'ends_on' => now()->addMonths(4)->toDateString()])->json('data.id');

        $cycle = $this->api('POST', 'exam-cycles', $examiner, ['name' => 'Nov 2026', 'type' => 'regular', 'term_id' => $term])->assertCreated()->assertJsonPath('data.status', 'pending_approval')->json('data.id');
        $this->api('POST', "exam-cycles/{$cycle}/open-registration", $examiner)->assertStatus(409);
        $this->api('POST', "exam-cycles/{$cycle}/approve", $examiner)->assertForbidden();

        $this->api('GET', "exam-cycles/{$cycle}", $principal)->assertOk()->assertJsonPath('data.can.approve', true);
        $this->api('POST', "exam-cycles/{$cycle}/approve", $principal)->assertOk()->assertJsonPath('data.status', 'draft');
        $this->api('POST', "exam-cycles/{$cycle}/open-registration", $examiner)->assertOk();

        $second = $this->api('POST', 'exam-cycles', $examiner, ['name' => 'Supplementary', 'type' => 'supplementary', 'term_id' => $term])->json('data.id');
        $this->api('POST', "exam-cycles/{$second}/reject", $principal)->assertUnprocessable();
        $this->api('POST', "exam-cycles/{$second}/reject", $principal, ['reason' => 'Dates clash with the university exam.'])->assertOk()
            ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.approval_remarks', 'Dates clash with the university exam.');

        $this->api('POST', 'exam-cycles', $admin, ['name' => 'By admin', 'type' => 'regular', 'term_id' => $term])->assertJsonPath('data.status', 'draft');
    }
}
