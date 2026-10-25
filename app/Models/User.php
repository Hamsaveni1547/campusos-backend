<?php

namespace App\Models;

use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\MfaMethod;
use App\Modules\Identity\Models\RecoveryCode;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Identity\Models\TrustedDevice;
use App\Modules\Identity\Notifications\ResetPasswordNotification;
use App\Modules\Institution\Models\Institution;
use App\Shared\Concerns\HasPublicId;
use App\Shared\Concerns\TracksAuthors;
use App\Shared\Files\StoredFile;
use App\Shared\Tenancy\CurrentInstitution;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

/**
 * One row per person. Role-specific profiles (student, employee, applicant)
 * live in their modules and reference this record.
 *
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property string $email
 * @property string|null $mobile
 * @property string|null $username
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $mobile_verified_at
 * @property string $password
 * @property UserStatus $status
 * @property bool $is_platform_admin
 * @property bool $must_change_password
 * @property Carbon|null $password_changed_at
 * @property int $failed_login_attempts
 * @property Carbon|null $locked_until
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property string $locale
 * @property string|null $timezone
 * @property int|null $avatar_file_id
 * @property Carbon|null $deactivated_at
 * @property string|null $deactivation_reason
 */
#[Fillable([
    'name', 'email', 'mobile', 'username', 'password', 'status', 'is_platform_admin', 'must_change_password',
    'password_changed_at', 'email_verified_at', 'mobile_verified_at', 'locale', 'timezone', 'avatar_file_id',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPublicId, HasRoles, LogsActivity, Notifiable, TracksAuthors;

    protected string $guard_name = 'web';

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'mobile_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'is_platform_admin' => 'boolean',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'failed_login_attempts' => 'integer',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('identity')
            ->logOnly(['name', 'email', 'mobile', 'username', 'status', 'is_platform_admin', 'locale', 'timezone', 'deactivation_reason'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return BelongsToMany<Institution, $this>
     */
    public function institutions(): BelongsToMany
    {
        return $this->belongsToMany(Institution::class)
            ->withPivot(['is_default', 'status', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<RoleAssignment, $this>
     */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    /**
     * @return HasMany<MfaMethod, $this>
     */
    public function mfaMethods(): HasMany
    {
        return $this->hasMany(MfaMethod::class);
    }

    /**
     * @return HasMany<RecoveryCode, $this>
     */
    public function recoveryCodes(): HasMany
    {
        return $this->hasMany(RecoveryCode::class);
    }

    /**
     * @return HasMany<TrustedDevice, $this>
     */
    public function trustedDevices(): HasMany
    {
        return $this->hasMany(TrustedDevice::class);
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function avatar(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'avatar_file_id');
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function hasMfaEnabled(): bool
    {
        return $this->mfaMethods()->confirmed()->exists();
    }

    public function belongsToInstitution(int $institutionId): bool
    {
        return $this->institutions()
            ->where('institutions.id', $institutionId)
            ->wherePivot('status', 'active')
            ->exists();
    }

    /**
     * Membership status (active/inactive) in the current institution, or null when not a member.
     */
    public function membershipStatus(): ?string
    {
        $institutionId = app(CurrentInstitution::class)->id();

        if ($institutionId === null) {
            return null;
        }

        $status = $this->institutions()->where('institutions.id', $institutionId)->first()?->getRelationValue('pivot')?->getAttribute('status');

        return $status === null ? null : (string) $status;
    }

    public function defaultInstitution(): ?Institution
    {
        /** @var Institution|null */
        return $this->institutions()
            ->wherePivot('status', 'active')
            ->orderByPivot('is_default', 'desc')
            ->orderBy('institutions.id')
            ->first();
    }

    /**
     * Departments where the user holds the given role right now (empty = no department limit).
     *
     * @return array<int, int>
     */
    public function departmentIdsForRole(string $roleName, int $institutionId): array
    {
        return $this->roleAssignments()
            ->withoutGlobalScopes()
            ->effective()
            ->where('institution_id', $institutionId)
            ->whereHas('role', fn ($query) => $query->where('name', $roleName))
            ->whereNotNull('department_id')
            ->pluck('department_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
