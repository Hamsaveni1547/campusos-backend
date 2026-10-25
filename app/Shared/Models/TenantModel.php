<?php

namespace App\Shared\Models;

use App\Shared\Concerns\BelongsToInstitution;
use App\Shared\Concerns\HasPublicId;
use App\Shared\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Base for institution-owned records: scoped to the current institution,
 * public ULID in URLs, created_by/updated_by, and audited changes.
 *
 * @property int $id
 * @property string $ulid
 * @property int $institution_id
 * @property int|null $created_by
 * @property int|null $updated_by
 */
abstract class TenantModel extends Model
{
    use BelongsToInstitution, HasPublicId, LogsActivity, TracksAuthors;

    /**
     * Audit log name, e.g. "institution" or "hr".
     */
    protected string $auditLog = 'default';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName($this->auditLog)
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
