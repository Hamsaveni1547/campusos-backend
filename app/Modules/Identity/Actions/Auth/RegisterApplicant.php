<?php

namespace App\Modules\Identity\Actions\Auth;

use App\Models\User;
use App\Modules\Identity\Actions\Roles\AssignRole;
use App\Modules\Identity\Authorization\SystemRole;
use App\Modules\Identity\Enums\OneTimeCodeChannel;
use App\Modules\Identity\Enums\OneTimeCodePurpose;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Services\OneTimeCodeService;
use App\Modules\Identity\Support\SecuritySettings;
use App\Modules\Institution\Models\Institution;
use App\Shared\Audit\SecurityEventType;
use App\Shared\Audit\SecurityLogger;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Support\Facades\DB;

/**
 * Public self-registration for admission applicants. The account starts in
 * "pending_verification" until the emailed code is confirmed.
 */
class RegisterApplicant
{
    public function __construct(
        private CurrentInstitution $currentInstitution,
        private AssignRole $assignRole,
        private OneTimeCodeService $codes,
        private SecuritySettings $settings,
        private SecurityLogger $securityLogger,
    ) {}

    /**
     * @param  array{name: string, email: string, mobile: string, password: string}  $data
     */
    public function handle(Institution $institution, array $data): User
    {
        if (! $institution->isActive() || ! $this->settings->bool('allow_applicant_registration', $institution->id)) {
            throw new BusinessRuleException('Online registration is not open for this institution.', 'REGISTRATION_CLOSED', 403);
        }

        return $this->currentInstitution->runAs($institution, function () use ($institution, $data): User {
            $user = DB::transaction(function () use ($institution, $data): User {
                $user = new User([
                    'name' => $data['name'],
                    'email' => mb_strtolower(trim($data['email'])),
                    'mobile' => $data['mobile'],
                ]);

                $user->forceFill([
                    'password' => $data['password'],
                    'status' => UserStatus::PendingVerification,
                    'password_changed_at' => now(),
                ])->save();

                $user->institutions()->attach($institution->id, ['status' => 'active', 'is_default' => true, 'joined_at' => now()]);

                $role = Role::query()->whereNull('institution_id')->where('name', SystemRole::Applicant->value)->firstOrFail();
                $this->assignRole->handle($user, $role, null, reason: 'Self-registration');

                return $user;
            });

            $this->securityLogger->log(SecurityEventType::UserCreated, $user, ['self_registered' => true]);
            $this->codes->issue($user, OneTimeCodePurpose::EmailVerification, OneTimeCodeChannel::Email, $institution->id);

            return $user;
        });
    }
}
