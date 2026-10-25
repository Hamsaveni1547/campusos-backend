<?php

namespace App\Shared\Numbering;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Generates gap-free, human-readable numbers (receipt no., application no., USN …)
 * per institution, sequence key and scope, using a row lock.
 *
 * Format tokens: {INST} institution code, {YYYY} {YY} {MM} year/month, {SCOPE},
 * {SEQ} or {SEQ:n} zero-padded counter, plus any {KEY} passed in $tokens.
 */
class NumberGenerator
{
    /**
     * @param  array<string, string|int>  $tokens
     */
    public function next(
        string $sequenceKey,
        string $format,
        ?int $institutionId = null,
        string $scope = '',
        array $tokens = [],
    ): string {
        if (! str_contains($format, '{SEQ')) {
            throw new InvalidArgumentException('A number format must contain a {SEQ} token.');
        }

        $value = $this->nextValue($sequenceKey, $institutionId, $scope);

        return $this->format($format, $value, $scope, $tokens);
    }

    public function nextValue(string $sequenceKey, ?int $institutionId = null, string $scope = ''): int
    {
        return DB::transaction(function () use ($sequenceKey, $institutionId, $scope): int {
            $query = DB::table('number_sequences')
                ->where('sequence_key', $sequenceKey)
                ->where('scope', $scope);

            $institutionId === null
                ? $query->whereNull('institution_id')
                : $query->where('institution_id', $institutionId);

            $row = (clone $query)->lockForUpdate()->first();

            if ($row === null) {
                DB::table('number_sequences')->insertOrIgnore([
                    'institution_id' => $institutionId,
                    'sequence_key' => $sequenceKey,
                    'scope' => $scope,
                    'last_value' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = (clone $query)->lockForUpdate()->first();
            }

            $next = (int) $row->last_value + 1;

            DB::table('number_sequences')->where('id', $row->id)->update([
                'last_value' => $next,
                'updated_at' => now(),
            ]);

            return $next;
        });
    }

    /**
     * @param  array<string, string|int>  $tokens
     */
    public function format(string $format, int $value, string $scope = '', array $tokens = []): string
    {
        $now = now();

        $replacements = array_merge([
            '{YYYY}' => $now->format('Y'),
            '{YY}' => $now->format('y'),
            '{MM}' => $now->format('m'),
            '{SCOPE}' => $scope,
        ], collect($tokens)->mapWithKeys(fn ($tokenValue, $key) => ['{'.strtoupper((string) $key).'}' => (string) $tokenValue])->all());

        $result = (string) preg_replace_callback(
            '/\{SEQ(?::(\d+))?\}/',
            fn (array $matches): string => str_pad((string) $value, (int) ($matches[1] ?? 0), '0', STR_PAD_LEFT),
            $format,
        );

        return strtr($result, $replacements);
    }
}
