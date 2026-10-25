<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Authorization\SystemRole;
use App\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * System roles have institution_id NULL and are shared by every institution.
 * Custom roles belong to one institution.
 *
 * @property int $id
 * @property string $ulid
 * @property int|null $institution_id
 * @property string $name
 * @property string|null $display_name
 * @property string|null $description
 * @property bool $is_system
 * @property bool $is_active
 * @property bool $is_department_scoped
 */
class Role extends SpatieRole
{
    use HasPublicId, LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'is_department_scoped' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('identity')
            ->logOnly(['name', 'display_name', 'description', 'is_active', 'is_department_scoped'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return HasMany<RoleAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    /**
     * Roles visible to an institution: system roles plus its own custom roles.
     *
     * @param  Builder<Role>  $query
     */
    public function scopeAvailableTo(Builder $query, int $institutionId): void
    {
        $query->where(function (Builder $query) use ($institutionId): void {
            $query->whereNull('institution_id')->orWhere('institution_id', $institutionId);
        });
    }

    public function systemRole(): ?SystemRole
    {
        return $this->is_system ? SystemRole::tryFrom($this->name) : null;
    }

    public function label(): string
    {
        return $this->display_name ?? $this->name;
    }
}
