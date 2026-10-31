<?php

namespace App\Modules\Students\Models;

use App\Models\User;
use App\Modules\Admissions\Enums\Quota;
use App\Modules\Admissions\Models\Application;
use App\Modules\Institution\Models\Batch;
use App\Modules\Institution\Models\Programme;
use App\Modules\Institution\Models\Section;
use App\Modules\Students\Enums\StudentStatus;
use App\Shared\Files\StoredFile;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The authoritative student record (one per user per institution).
 *
 * @property int $user_id
 * @property string $register_no
 * @property string|null $roll_no
 * @property int $programme_id
 * @property int|null $batch_id
 * @property int|null $section_id
 * @property int|null $application_id
 * @property Quota $admission_type
 * @property string|null $category
 * @property StudentStatus $status
 * @property Carbon $admitted_on
 * @property int $current_term_number
 * @property Carbon|null $date_of_birth
 * @property string|null $gender
 * @property string|null $blood_group
 * @property string|null $nationality
 * @property string|null $religion
 * @property string|null $mother_tongue
 * @property string|null $address
 * @property string|null $permanent_address
 * @property string|null $id_proof_type
 * @property string|null $id_proof_number
 * @property int|null $photo_file_id
 * @property Carbon|null $left_on
 * @property string|null $exit_reason
 * @property-read User $user
 * @property-read Programme $programme
 * @property-read Batch|null $batch
 * @property-read Section|null $section
 * @property-read StoredFile|null $photo
 */
#[Fillable([
    'user_id', 'register_no', 'roll_no', 'programme_id', 'batch_id', 'section_id', 'application_id', 'admission_type', 'category',
    'admitted_on', 'current_term_number', 'date_of_birth', 'gender', 'blood_group', 'nationality', 'religion', 'mother_tongue',
    'address', 'permanent_address', 'id_proof_type', 'id_proof_number', 'photo_file_id',
])]
class Student extends TenantModel
{
    protected string $auditLog = 'students';

    protected $attributes = ['status' => 'active', 'current_term_number' => 1];

    /**
     * Encrypted identity number is never written to the audit log.
     *
     * @var list<string>
     */
    protected $hidden = ['id_proof_number'];

    protected function casts(): array
    {
        return [
            'admission_type' => Quota::class,
            'status' => StudentStatus::class,
            'admitted_on' => 'date',
            'date_of_birth' => 'date',
            'left_on' => 'date',
            'current_term_number' => 'integer',
            'id_proof_number' => 'encrypted',
        ];
    }

    /**
     * Limit to students of the given departments (via programme). Empty = no limit.
     *
     * @param  Builder<Student>  $query
     * @param  array<int, int>  $departmentIds
     */
    public function scopeInDepartments(Builder $query, array $departmentIds): void
    {
        if ($departmentIds !== []) {
            $query->whereHas('programme', fn (Builder $query) => $query->whereIn('department_id', $departmentIds));
        }
    }

    /**
     * Masked identity number, e.g. XXXX-XXXX-1234.
     */
    public function maskedIdProof(): ?string
    {
        if ($this->id_proof_number === null || $this->id_proof_number === '') {
            return null;
        }

        return str_repeat('•', max(0, mb_strlen($this->id_proof_number) - 4)).mb_substr($this->id_proof_number, -4);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Programme, $this>
     */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    /**
     * @return BelongsTo<Batch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * @return BelongsTo<Section, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'photo_file_id');
    }

    /**
     * @return HasMany<StudentGuardian, $this>
     */
    public function guardians(): HasMany
    {
        return $this->hasMany(StudentGuardian::class)->orderByDesc('is_primary')->orderBy('id');
    }

    /**
     * @return HasMany<StudentStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(StudentStatusHistory::class)->orderBy('id');
    }

    /**
     * @return HasMany<StudentDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(StudentDocument::class);
    }

    /**
     * @return HasMany<ProfileChangeRequest, $this>
     */
    public function changeRequests(): HasMany
    {
        return $this->hasMany(ProfileChangeRequest::class)->latest('id');
    }
}
