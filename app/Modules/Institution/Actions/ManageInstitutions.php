<?php

namespace App\Modules\Institution\Actions;

use App\Models\User;
use App\Modules\Identity\Actions\Users\CreateUser;
use App\Modules\Identity\Authorization\SystemRole;
use App\Modules\Identity\Models\PersonalAccessToken;
use App\Modules\Identity\Models\Role;
use App\Modules\Institution\Enums\InstitutionStatus;
use App\Modules\Institution\Models\Institution;
use App\Shared\Audit\SecurityEventType;
use App\Shared\Audit\SecurityLogger;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Support\Facades\DB;

/**
 * Platform-level lifecycle of institutions (tenants).
 */
class ManageInstitutions
{
    public function __construct(
        private CurrentInstitution $currentInstitution,
        private CreateUser $createUser,
        private SecurityLogger $securityLogger,
    ) {}

    /**
     * Create an institution and its first administrator (invited by email).
     *
     * @param  array<string, mixed>  $attributes
     * @param  array{name: string, email: string, mobile?: string|null}  $admin
     * @return array{institution: Institution, admin: User}
     */
    public function provision(array $attributes, array $admin, User $actor): array
    {
        $attributes['code'] = strtoupper((string) $attributes['code']);

        if (isset($attributes['parent_id'])) {
            $parent = Institution::query()->findOrFail($attributes['parent_id']);

            if ($parent->parent_id !== null) {
                throw new BusinessRuleException('Only two levels are supported: a university and its colleges.', 'INSTITUTION_HIERARCHY_TOO_DEEP');
            }
        }

        return DB::transaction(function () use ($attributes, $admin, $actor): array {
            $institution = Institution::query()->create(array_merge($attributes, ['status' => InstitutionStatus::Active]));

            $adminUser = $this->currentInstitution->runAs($institution, function () use ($admin, $actor): User {
                $role = Role::query()->whereNull('institution_id')->where('name', SystemRole::InstitutionAdmin->value)->firstOrFail();

                return $this->createUser->handle($admin, $actor, [$role])['user'];
            });

            return ['institution' => $institution, 'admin' => $adminUser];
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Institution $institution, array $attributes): Institution
    {
        if (isset($attributes['code'])) {
            $attributes['code'] = strtoupper((string) $attributes['code']);
        }

        $institution->fill($attributes)->save();

        return $institution;
    }

    public function suspend(Institution $institution, string $reason): Institution
    {
        if ($institution->status === InstitutionStatus::Suspended) {
            throw new BusinessRuleException('The institution is already suspended.', 'INSTITUTION_ALREADY_SUSPENDED', 409);
        }

        DB::transaction(function () use ($institution, $reason): void {
            $institution->forceFill([
                'status' => InstitutionStatus::Suspended,
                'suspended_at' => now(),
                'suspension_reason' => $reason,
            ])->save();

            PersonalAccessToken::query()->where('institution_id', $institution->id)->delete();
        });

        $this->securityLogger->log(SecurityEventType::InstitutionSuspended, null, ['reason' => $reason], institutionId: $institution->id);

        return $institution;
    }

    public function reactivate(Institution $institution): Institution
    {
        if ($institution->status === InstitutionStatus::Active) {
            throw new BusinessRuleException('The institution is already active.', 'INSTITUTION_ALREADY_ACTIVE', 409);
        }

        $institution->forceFill([
            'status' => InstitutionStatus::Active,
            'suspended_at' => null,
            'suspension_reason' => null,
        ])->save();

        $this->securityLogger->log(SecurityEventType::InstitutionReactivated, null, institutionId: $institution->id);

        return $institution;
    }
}
