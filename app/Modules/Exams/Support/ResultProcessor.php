<?php

namespace App\Modules\Exams\Support;

/**
 * Turns internal and external marks into a grade, and grades into GPA. Pure and deterministic.
 *
 * Total % = (internal + external) ÷ (internal out of + external max) × 100.
 * A student passes when the external % and the total % both reach the pass marks; the grade is then
 * the highest band whose minimum the total reaches. Otherwise F. Absent = AB, not eligible = NE (both 0 points).
 */
final class ResultProcessor
{
    /**
     * @param  array{external_pass_percent: float, overall_pass_percent: float, grades: list<array{grade: string, min: float, points: float}>}  $rules
     * @return array{total_percent: float|null, grade: string, points: float, passed: bool}
     */
    public static function grade(?float $internal, ?float $internalOutOf, ?float $external, float $externalMax, ?string $attendance, bool $eligible, array $rules): array
    {
        if (! $eligible) {
            return ['total_percent' => null, 'grade' => 'NE', 'points' => 0.0, 'passed' => false];
        }

        if ($attendance === 'absent' || $external === null) {
            return ['total_percent' => null, 'grade' => 'AB', 'points' => 0.0, 'passed' => false];
        }

        $externalPercent = $externalMax > 0 ? $external * 100 / $externalMax : 0.0;
        $outOf = (float) $internalOutOf + $externalMax;
        $total = $outOf > 0 ? round(((float) $internal + $external) * 100 / $outOf, 2) : 0.0;

        if ($externalPercent < $rules['external_pass_percent'] || $total < $rules['overall_pass_percent']) {
            return ['total_percent' => $total, 'grade' => 'F', 'points' => 0.0, 'passed' => false];
        }

        $bands = $rules['grades'];
        usort($bands, fn (array $a, array $b): int => $b['min'] <=> $a['min']);

        foreach ($bands as $band) {
            if ($total >= $band['min']) {
                return ['total_percent' => $total, 'grade' => $band['grade'], 'points' => (float) $band['points'], 'passed' => true];
            }
        }

        $lowest = end($bands);

        return ['total_percent' => $total, 'grade' => $lowest === false ? 'P' : $lowest['grade'], 'points' => $lowest === false ? 0.0 : (float) $lowest['points'], 'passed' => true];
    }

    /**
     * Credit-weighted grade point average; null when there are no credits.
     *
     * @param  list<array{credits: float, points: float}>  $courses
     */
    public static function gpa(array $courses): ?float
    {
        $credits = array_sum(array_column($courses, 'credits'));

        if ($credits <= 0) {
            return null;
        }

        return round(array_sum(array_map(fn (array $c): float => $c['credits'] * $c['points'], $courses)) / $credits, 2);
    }
}
