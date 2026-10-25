<?php

namespace App\Modules\HR\Models;

use App\Shared\Files\StoredFile;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $employee_id
 * @property string $degree
 * @property int|null $year_of_passing
 * @property int|null $certificate_file_id
 * @property-read Employee $employee
 * @property-read StoredFile|null $certificate
 */
#[Fillable(['employee_id', 'degree', 'specialization', 'university', 'year_of_passing', 'grade', 'certificate_file_id'])]
class EmployeeQualification extends TenantModel
{
    protected string $auditLog = 'hr';

    protected function casts(): array
    {
        return [
            'year_of_passing' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'certificate_file_id');
    }
}
