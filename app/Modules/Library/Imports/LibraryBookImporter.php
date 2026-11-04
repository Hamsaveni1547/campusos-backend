<?php

namespace App\Modules\Library\Imports;

use App\Models\User;
use App\Modules\Library\Authorization\LibraryPermissions;
use App\Modules\Library\Models\LibraryBook;
use App\Modules\Library\Models\LibraryCopy;
use App\Shared\Imports\Import;
use App\Shared\Imports\Importer;
use App\Shared\Imports\ImportRunContext;

/**
 * CSV columns: title, authors — required; isbn, publisher, edition, published_year, subject, call_number,
 * accession_nos (several separated by ";"), shelf — optional. A row whose ISBN is already in the catalogue
 * adds its copies to that book.
 */
class LibraryBookImporter implements Importer
{
    public function permission(): string
    {
        return LibraryPermissions::CatalogueManage;
    }

    public function requiredColumns(): array
    {
        return ['title', 'authors'];
    }

    public function optionalColumns(): array
    {
        return ['isbn', 'publisher', 'edition', 'published_year', 'subject', 'call_number', 'accession_nos', 'shelf'];
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:300'],
            'authors' => ['required', 'string', 'max:300'],
            'isbn' => ['nullable', 'string', 'max:20'],
            'publisher' => ['nullable', 'string', 'max:150'],
            'edition' => ['nullable', 'string', 'max:50'],
            'published_year' => ['nullable', 'integer', 'min:1500', 'max:'.(now()->year + 1)],
            'subject' => ['nullable', 'string', 'max:100'],
            'call_number' => ['nullable', 'string', 'max:50'],
            'accession_nos' => ['nullable', 'string', 'max:1000'],
            'shelf' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function check(array $row, ImportRunContext $context): array
    {
        $errors = [];

        foreach ($this->accessions($row) as $accession) {
            if (mb_strlen($accession) > 30) {
                $errors[] = "Accession number \"{$accession}\" is longer than 30 characters.";
            }
            if ($context->remember('accession_no', $accession, 0) !== null) {
                $errors[] = "Accession number \"{$accession}\" appears more than once in the file.";
            }
            if (LibraryCopy::query()->where('accession_no', $accession)->exists()) {
                $errors[] = "Accession number \"{$accession}\" is already in the library.";
            }
        }

        return $errors;
    }

    public function import(array $row, Import $import, User $actor): void
    {
        $book = filled($row['isbn'] ?? null) ? LibraryBook::query()->where('isbn', $row['isbn'])->first() : null;

        $book ??= LibraryBook::query()->create([
            'title' => $row['title'],
            'authors' => $row['authors'],
            'isbn' => $row['isbn'] ?? null,
            'publisher' => $row['publisher'] ?? null,
            'edition' => $row['edition'] ?? null,
            'published_year' => filled($row['published_year'] ?? null) ? (int) $row['published_year'] : null,
            'subject' => $row['subject'] ?? null,
            'call_number' => $row['call_number'] ?? null,
        ]);

        foreach ($this->accessions($row) as $accession) {
            $copy = LibraryCopy::query()->create(['library_book_id' => $book->id, 'accession_no' => $accession, 'shelf' => $row['shelf'] ?? null]);
            $copy->forceFill(['status' => 'available'])->save();
        }
    }

    /**
     * @param  array<string, string|null>  $row
     * @return list<string>
     */
    private function accessions(array $row): array
    {
        return array_values(array_filter(array_map('trim', explode(';', (string) ($row['accession_nos'] ?? ''))), fn (string $a): bool => $a !== ''));
    }
}
