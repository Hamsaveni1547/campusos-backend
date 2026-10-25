<?php

namespace App\Shared\Files;

use App\Models\User;
use App\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Metadata for an uploaded file kept on a private disk.
 *
 * @property int $id
 * @property string $ulid
 * @property int|null $institution_id
 * @property string $purpose
 * @property FileClassification $classification
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $checksum_sha256
 * @property int|null $uploaded_by
 * @property string|null $attachable_type
 * @property int|null $attachable_id
 */
#[Fillable(['institution_id', 'attachable_type', 'attachable_id', 'purpose', 'classification', 'disk', 'path', 'original_name', 'mime_type', 'extension', 'size_bytes', 'checksum_sha256', 'uploaded_by'])]
class StoredFile extends Model
{
    use HasPublicId, SoftDeletes;

    protected $table = 'files';

    protected function casts(): array
    {
        return [
            'classification' => FileClassification::class,
            'size_bytes' => 'integer',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return HasMany<FileAccessLog, $this>
     */
    public function accessLogs(): HasMany
    {
        return $this->hasMany(FileAccessLog::class, 'file_id');
    }

    public function isAttached(): bool
    {
        return $this->attachable_type !== null;
    }
}
