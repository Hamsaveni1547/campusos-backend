<?php

namespace App\Modules\Identity\Models;

use App\Models\User;
use App\Shared\Concerns\BelongsToInstitution;
use App\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Who holds which role, where (department) and for how long.
 * The source of truth for spatie's model_has_roles rows.
 *
 * @property int $id
 * @property string $ulid
 * @property int $institution_id
 * @property int $user_id
 * @property int $role_id
 * @property int|null $department_id
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_until
 * @property Carbon|null $revoked_at
 * @property string|null $reason
 * @property-read Role $role
 * @property-read User $user
 */
#[Fillable(['institution_id', 'user_id', 'role_id', 'department_id', 'valid_from', 'valid_until', 'reason', 'assigned_by', 'revoked_at', 'revoked_by', 'revocation_reason'])]
class RoleAssignment extends Model
{
    use BelongsToInstitution, HasPublicId, LogsActivity;

    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('identity')
            ->logOnly(['user_id', 'role_id', 'department_id', 'valid_from', 'valid_until', 'revoked_at', 'revocation_reason'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * Assignments that grant access right now.
     *
     * @param  Builder<RoleAssignment>  $query
     */
    public function scopeEffective(Builder $query): void
    {
        $now = now();

        $query->whereNull('revoked_at')
            ->where(fn (Builder $query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
            ->where(fn (Builder $query) => $query->whereNull('valid_until')->orWhere('valid_until', '>', $now));
    }

    public function isEffective(): bool
    {
        $now = now();

        return $this->revoked_at === null
            && ($this->valid_from === null || $this->valid_from->lte($now))
            && ($this->valid_until === null || $this->valid_until->gt($now));
    }

    public function status(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->valid_until !== null && $this->valid_until->lte(now()) => 'expired',
            $this->valid_from !== null && $this->valid_from->gt(now()) => 'scheduled',
            default => 'active',
        };
    }
}
