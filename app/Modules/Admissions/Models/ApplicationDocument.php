<?php

namespace App\Modules\Admissions\Models;

use App\Modules\Admissions\Enums\DocumentStatus;
use App\Shared\Files\StoredFile;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $application_id
 * @property string $document_type
 * @property int $file_id
 * @property DocumentStatus $status
 * @property string|null $remarks
 * @property int|null $verified_by
 * @property Carbon|null $verified_at
 * @property-read StoredFile $file
 */
#[Fillable(['application_id', 'document_type', 'file_id', 'status', 'remarks', 'verified_by', 'verified_at'])]
class ApplicationDocument extends TenantModel
{
    protected string $auditLog = 'admissions';

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['status' => DocumentStatus::class, 'verified_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'file_id');
    }

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
