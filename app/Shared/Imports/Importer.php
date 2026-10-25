<?php

namespace App\Shared\Imports;

use App\Models\User;

/**
 * Contract for a bulk import type (registered in config/campusos.php "importers").
 */
interface Importer
{
    /**
     * Permission required to run this import.
     */
    public function permission(): string;

    /**
     * Required CSV columns (header names, lowercase snake_case).
     *
     * @return array<int, string>
     */
    public function requiredColumns(): array;

    /**
     * Optional CSV columns.
     *
     * @return array<int, string>
     */
    public function optionalColumns(): array;

    /**
     * Laravel validation rules for one row.
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * Extra checks needing context (duplicates in the file, lookups).
     * Called for every row in order; return error messages.
     *
     * @param  array<string, string|null>  $row
     * @return array<int, string>
     */
    public function check(array $row, ImportRunContext $context): array;

    /**
     * Persist one validated row.
     *
     * @param  array<string, string|null>  $row
     */
    public function import(array $row, Import $import, User $actor): void;
}
