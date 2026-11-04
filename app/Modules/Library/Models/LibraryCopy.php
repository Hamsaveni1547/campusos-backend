<?php

namespace App\Modules\Library\Models;

use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $library_book_id
 * @property string $accession_no
 * @property string|null $shelf
 * @property string $status available|issued|lost|withdrawn
 * @property-read LibraryBook $book
 */
#[Fillable(['library_book_id', 'accession_no', 'shelf', 'status'])]
class LibraryCopy extends TenantModel
{
    public const STATUSES = ['available', 'issued', 'lost', 'withdrawn'];

    protected string $auditLog = 'library';

    /**
     * @return BelongsTo<LibraryBook, $this>
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(LibraryBook::class, 'library_book_id');
    }
}
