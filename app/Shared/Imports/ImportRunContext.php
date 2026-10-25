<?php

namespace App\Shared\Imports;

/**
 * Per-run memory for importers, e.g. to detect duplicates within a file.
 */
class ImportRunContext
{
    /**
     * @var array<string, array<string, int>>
     */
    private array $seen = [];

    /**
     * Remember a value for a column; returns the first row number it was seen on, if any.
     */
    public function remember(string $column, ?string $value, int $rowNumber): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = mb_strtolower(trim($value));

        if (isset($this->seen[$column][$normalized])) {
            return $this->seen[$column][$normalized];
        }

        $this->seen[$column][$normalized] = $rowNumber;

        return null;
    }
}
