<?php

namespace Tests\Unit;

use App\Modules\Assessment\Support\InternalMarksCalculator;
use PHPUnit\Framework\TestCase;

class InternalMarksCalculatorTest extends TestCase
{
    /**
     * IA tests: best 2 of 3 (each out of 50) worth 30; assignment out of 10 worth 10.
     *
     * @return list<array{name: string, best_of: int|null, weight: float, assessments: list<array{id: int, name: string, max: float}>}>
     */
    private function scheme(): array
    {
        return [
            ['name' => 'IA tests', 'best_of' => 2, 'weight' => 30.0, 'assessments' => [['id' => 1, 'name' => 'IA1', 'max' => 50.0], ['id' => 2, 'name' => 'IA2', 'max' => 50.0], ['id' => 3, 'name' => 'IA3', 'max' => 50.0]]],
            ['name' => 'Assignment', 'best_of' => null, 'weight' => 10.0, 'assessments' => [['id' => 4, 'name' => 'A1', 'max' => 10.0]]],
        ];
    }

    public function test_best_of_two_scaled_to_weight_and_rounded_half_up(): void
    {
        $marks = [
            1 => [7 => ['marks' => 40.0, 'status' => 'present']],
            2 => [7 => ['marks' => 25.0, 'status' => 'present']],
            3 => [7 => ['marks' => 45.0, 'status' => 'present']],
            4 => [7 => ['marks' => 7.5, 'status' => 'present']],
        ];

        $result = InternalMarksCalculator::compute($this->scheme(), $marks, [7])[7];

        // IA: best 45/50 and 40/50 → (0.9 + 0.8) / 2 × 30 = 25.5; assignment 0.75 × 10 = 7.5 → 33.0
        $this->assertSame(33.0, $result['computed']);
        $this->assertSame(33.0, $result['final']);
        $this->assertSame(['IA3', 'IA1'], $result['breakdown'][0]['counted']);
        $this->assertSame(25.5, $result['breakdown'][0]['score']);
    }

    public function test_absent_counts_zero_exempt_is_left_out_and_missing_counts_zero(): void
    {
        $marks = [
            1 => [7 => ['marks' => null, 'status' => 'absent'], 8 => ['marks' => null, 'status' => 'exempt']],
            2 => [7 => ['marks' => 30.0, 'status' => 'present'], 8 => ['marks' => 33.0, 'status' => 'present']],
            3 => [8 => ['marks' => 50.0, 'status' => 'present']],
            4 => [8 => ['marks' => 9.0, 'status' => 'present']],
        ];

        $results = InternalMarksCalculator::compute($this->scheme(), $marks, [7, 8]);

        // Student 7: IA best two of (0, 0.6, 0) → 0.3 × 30 = 9; assignment missing → 0
        $this->assertSame(9.0, $results[7]['computed']);
        // Student 8: IA1 exempt → best two of (0.66, 1.0) → 0.83 × 30 = 24.9; assignment 9 → 33.9 → 34
        $this->assertSame(33.9, $results[8]['computed']);
        $this->assertSame(34.0, $results[8]['final']);
    }

    public function test_marks_above_max_are_capped_and_rounding_can_be_off(): void
    {
        $scheme = [['name' => 'Quiz', 'best_of' => null, 'weight' => 5.0, 'assessments' => [['id' => 1, 'name' => 'Q1', 'max' => 10.0], ['id' => 2, 'name' => 'Q2', 'max' => 10.0]]]];
        $marks = [1 => [1 => ['marks' => 12.0, 'status' => 'present']], 2 => [1 => ['marks' => 4.5, 'status' => 'present']]];

        $result = InternalMarksCalculator::compute($scheme, $marks, [1], roundFinal: false)[1];

        $this->assertSame(3.63, $result['computed']);
        $this->assertSame(3.63, $result['final']);
    }
}
