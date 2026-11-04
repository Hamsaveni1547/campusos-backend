<?php

namespace App\Modules\Library\Models;

use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $title
 * @property string $authors
 * @property string|null $isbn
 * @property string|null $publisher
 * @property string|null $edition
 * @property int|null $published_year
 * @property string|null $subject
 * @property string|null $call_number
 * @property-read int|null $copies_count
 * @property-read int|null $available_count
 */
#[Fillable(['title', 'authors', 'isbn', 'publisher', 'edition', 'published_year', 'subject', 'call_number'])]
class LibraryBook extends TenantModel
{
    protected string $auditLog = 'library';

    /**
     * @return HasMany<LibraryCopy, $this>
     */
    public function copies(): HasMany
    {
        return $this->hasMany(LibraryCopy::class);
    }
}
