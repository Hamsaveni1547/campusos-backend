<?php

namespace App\Modules\Students\Models;

use App\Modules\Students\Enums\ReviewStatus;
use App\Shared\Files\StoredFile;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $student_id
 * @property string $document_type
 * @property int $file_id
 * @property ReviewStatus $status
 * @property string|null $remarks
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property-read StoredFile $file
 */
#[Fillable(['student_id', 'document_type', 'file_id', 'status', 'remarks', 'reviewed_by', 'reviewed_at'])]
class StudentDocument extends TenantModel
{
    protected string $auditLog = 'students';

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['status' => ReviewStatus::class, 'reviewed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'file_id');
    }
}
