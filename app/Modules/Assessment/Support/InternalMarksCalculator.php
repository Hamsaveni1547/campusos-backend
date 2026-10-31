<?php

namespace App\Modules\Assessment\Support;

/**
 * Turns assessment marks into internal marks. Pure and deterministic.
 *
 * For each group: every assessment becomes a fraction (marks / max; absent = 0; exempt is left out),
 * the best N fractions are kept (all when N is empty), averaged, and multiplied by the group weight.
 * Internal marks = sum of groups. The final mark is rounded half up to a whole number when asked.
 */
final class InternalMarksCalculator
{
    /**
     * @param  list<array{name: string, best_of: int|null, weight: float, assessments: list<array{id: int, name: string, max: float}>}>  $groups
     * @param  array<int, array<int, array{marks: float|null, status: string}>>  $marks  assessment id => student id => mark
     * @param  list<int>  $studentIds
     * @return array<int, array{computed: float, final: float, breakdown: list<array{group: string, score: float, weight: float, counted: list<string>}>}>
     */
    public static function compute(array $groups, array $marks, array $studentIds, bool $roundFinal = true): array
    {
        $results = [];

        foreach ($studentIds as $studentId) {
            $total = 0.0;
            $breakdown = [];

            foreach ($groups as $group) {
                $fractions = [];

                foreach ($group['assessments'] as $assessment) {
                    $mark = $marks[$assessment['id']][$studentId] ?? null;

                    if ($mark !== null && $mark['status'] === 'exempt') {
                        continue;
                    }

                    $value = ($mark === null || $mark['status'] === 'absent' || $mark['marks'] === null) ? 0.0 : (float) $mark['marks'];
                    $fractions[$assessment['name']] = $assessment['max'] > 0 ? min(1.0, $value / $assessment['max']) : 0.0;
                }

                arsort($fractions);

                if ($group['best_of'] !== null && $group['best_of'] > 0) {
                    $fractions = array_slice($fractions, 0, $group['best_of'], true);
                }

                $score = $fractions === [] ? 0.0 : array_sum($fractions) / count($fractions) * $group['weight'];
                $score = round($score, 2);
                $total += $score;

                $breakdown[] = ['group' => $group['name'], 'score' => $score, 'weight' => $group['weight'], 'counted' => array_keys($fractions)];
            }

            $total = round($total, 2);
            $results[$studentId] = [
                'computed' => $total,
                'final' => $roundFinal ? (float) floor($total + 0.5) : $total,
                'breakdown' => $breakdown,
            ];
        }

        return $results;
    }
}
