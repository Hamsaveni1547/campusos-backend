<?php

namespace App\Modules\QuestionBank\Support;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Picks questions for each blueprint section. Pure and deterministic for a given seed.
 *
 * A section asks for `count` questions of a type and marks, optionally limited to units, Bloom levels
 * and a difficulty. Preferred candidates are those not in `$avoid` (recently used or used by another
 * set); when they run short, avoided ones fill the gap. Returns null for a section that cannot be filled.
 */
final class PaperGenerator
{
    /**
     * @param  list<array{type: string, marks: float, count: int, units?: list<int>|null, bloom_levels?: list<string>|null, difficulty?: string|null}>  $sections
     * @param  list<array{id: int, type: string, marks: float, unit: int|null, bloom_level: string|null, difficulty: string}>  $questions
     * @param  list<int>  $avoid
     * @return array{sections: list<list<int>>, short: int|null}
     */
    public static function generate(array $sections, array $questions, array $avoid, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $taken = [];
        $result = [];

        foreach ($sections as $index => $section) {
            $matching = array_values(array_filter($questions, fn (array $q): bool => ! in_array($q['id'], $taken, true)
                && $q['type'] === $section['type']
                && abs($q['marks'] - $section['marks']) < 0.001
                && (empty($section['units']) || in_array($q['unit'], $section['units'], true))
                && (empty($section['bloom_levels']) || in_array($q['bloom_level'], $section['bloom_levels'], true))
                && (empty($section['difficulty']) || $q['difficulty'] === $section['difficulty'])));

            $fresh = array_values(array_filter($matching, fn (array $q): bool => ! in_array($q['id'], $avoid, true)));
            $used = array_values(array_filter($matching, fn (array $q): bool => in_array($q['id'], $avoid, true)));
            $pool = [...($fresh === [] ? [] : $random->shuffleArray($fresh)), ...($used === [] ? [] : $random->shuffleArray($used))];

            if (count($pool) < $section['count']) {
                return ['sections' => $result, 'short' => $index];
            }

            $picked = array_map(fn (array $q): int => $q['id'], array_slice($pool, 0, $section['count']));
            $taken = [...$taken, ...$picked];
            $result[] = $picked;
        }

        return ['sections' => $result, 'short' => null];
    }
}
