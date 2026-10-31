<?php

namespace Tests\Unit;

use App\Modules\Exams\Support\ResultProcessor;
use PHPUnit\Framework\TestCase;

class ResultProcessorTest extends TestCase
{
    /**
     * @return array{external_pass_percent: float, overall_pass_percent: float, grades: list<array{grade: string, min: float, points: float}>}
     */
    private function rules(): array
    {
        return ['external_pass_percent' => 35.0, 'overall_pass_percent' => 40.0, 'grades' => [
            ['grade' => 'P', 'min' => 40.0, 'points' => 4.0],
            ['grade' => 'O', 'min' => 90.0, 'points' => 10.0],
            ['grade' => 'A', 'min' => 70.0, 'points' => 8.0],
        ]];
    }

    public function test_grade_is_the_highest_band_reached(): void
    {
        // (33 + 45) / (40 + 60) = 78% → A
        $this->assertSame(['total_percent' => 78.0, 'grade' => 'A', 'points' => 8.0, 'passed' => true], ResultProcessor::grade(33, 40, 45, 60, 'present', true, $this->rules()));
        $this->assertSame('O', ResultProcessor::grade(40, 40, 55, 60, 'present', true, $this->rules())['grade']);
        $this->assertSame('P', ResultProcessor::grade(20, 40, 25, 60, 'present', true, $this->rules())['grade']);
    }

    public function test_external_minimum_absent_and_not_eligible(): void
    {
        // Strong internal but 20/60 external (33%) fails the external minimum.
        $this->assertSame(['total_percent' => 60.0, 'grade' => 'F', 'points' => 0.0, 'passed' => false], ResultProcessor::grade(40, 40, 20, 60, 'present', true, $this->rules()));
        $this->assertSame('AB', ResultProcessor::grade(40, 40, null, 60, 'absent', true, $this->rules())['grade']);
        $this->assertSame('NE', ResultProcessor::grade(40, 40, 50, 60, 'present', false, $this->rules())['grade']);
    }

    public function test_without_internal_marks_the_external_alone_counts(): void
    {
        $this->assertSame(75.0, ResultProcessor::grade(null, null, 75, 100, 'present', true, $this->rules())['total_percent']);
    }

    public function test_gpa_is_credit_weighted(): void
    {
        $this->assertSame(7.14, ResultProcessor::gpa([['credits' => 4.0, 'points' => 8.0], ['credits' => 3.0, 'points' => 6.0]]));
        $this->assertNull(ResultProcessor::gpa([]));
    }
}
