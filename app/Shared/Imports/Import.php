<?php

namespace App\Shared\Imports;

use App\Models\User;
use App\Shared\Concerns\BelongsToInstitution;
use App\Shared\Concerns\HasPublicId;
use App\Shared\Files\StoredFile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A bulk import: uploaded CSV → validated (dry run) → committed.
 *
 * @property int $id
 * @property string $ulid
 * @property int|null $institution_id
 * @property string $type
 * @property int $file_id
 * @property ImportStatus $status
 * @property array<string, mixed>|null $options
 * @property int $total_rows
 * @property int $valid_rows
 * @property int $processed_rows
 * @property int $failed_rows
 * @property array<int, array{row: int, errors: array<int, string>}>|null $errors
 * @property int|null $created_by
 * @property string|null $failure_message
 * @property Carbon|null $validated_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
#[Fillable(['institution_id', 'type', 'file_id', 'status', 'options', 'total_rows', 'valid_rows', 'processed_rows', 'failed_rows', 'errors', 'failure_message', 'created_by', 'validated_at', 'started_at', 'finished_at'])]
class Import extends Model
{
    use BelongsToInstitution, HasPublicId;

    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'options' => 'array',
            'errors' => 'array',
            'validated_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'file_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
