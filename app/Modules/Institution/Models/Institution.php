<?php

namespace App\Modules\Institution\Models;

use App\Models\User;
use App\Modules\Institution\Enums\InstitutionStatus;
use App\Modules\Institution\Enums\InstitutionType;
use App\Shared\Concerns\HasPublicId;
use App\Shared\Concerns\TracksAuthors;
use App\Shared\Files\StoredFile;
use Database\Factories\InstitutionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The tenant. A university can be the parent of constituent colleges.
 *
 * @property int $id
 * @property string $ulid
 * @property int|null $parent_id
 * @property string $code
 * @property string $name
 * @property string|null $short_name
 * @property InstitutionType $type
 * @property InstitutionStatus $status
 * @property string $timezone
 * @property string $currency
 * @property int|null $logo_file_id
 * @property Carbon|null $suspended_at
 */
#[Fillable([
    'parent_id', 'code', 'name', 'short_name', 'type', 'status', 'email', 'phone', 'website',
    'address_line', 'city', 'state', 'country', 'postal_code', 'timezone', 'currency',
    'affiliation', 'accreditation_codes', 'established_year', 'logo_file_id', 'suspended_at', 'suspension_reason',
])]
class Institution extends Model
{
    /** @use HasFactory<InstitutionFactory> */
    use HasFactory, HasPublicId, LogsActivity, TracksAuthors;

    protected function casts(): array
    {
        return [
            'type' => InstitutionType::class,
            'status' => InstitutionStatus::class,
            'established_year' => 'integer',
            'suspended_at' => 'datetime',
        ];
    }

    protected static function newFactory(): InstitutionFactory
    {
        return InstitutionFactory::new();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('institution')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return BelongsTo<Institution, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Institution, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['is_default', 'status', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function logo(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'logo_file_id');
    }

    public function isActive(): bool
    {
        return $this->status === InstitutionStatus::Active;
    }
}
