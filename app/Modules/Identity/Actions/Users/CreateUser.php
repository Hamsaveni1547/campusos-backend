<?php

namespace App\Modules\Identity\Actions\Users;

use App\Models\User;
use App\Modules\Identity\Actions\Roles\AssignRole;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Notifications\AccountSetupNotification;
use App\Shared\Audit\SecurityEventType;
use App\Shared\Audit\SecurityLogger;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Creates (or links) a user in the current institution and optionally assigns roles.
 * If the email already exists, the existing person is added to this institution
 * instead of creating a duplicate identity.
 */
class CreateUser
{
    public function __construct(
        private CurrentInstitution $currentInstitution,
        private AssignRole $assignRole,
        private SecurityLogger $securityLogger,
    ) {}

    /**
     * @param  array{name: string, email: string, mobile?: string|null, username?: string|null, locale?: string|null, timezone?: string|null}  $attributes
     * @param  array<int, Role>  $roles
     * @return array{user: User, temporary_password: string|null, linked_existing: bool}
     */
    public function handle(array $attributes, ?User $actor, array $roles = [], bool $sendInvite = true, ?string $initialPassword = null): array
    {
        $institution = $this->currentInstitution->require();
        $email = mb_strtolower(trim($attributes['email']));

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null && $existing->belongsToInstitution($institution->id)) {
            throw new BusinessRuleException('A user with this email already exists in this institution.', 'USER_ALREADY_EXISTS', 409);
        }

        $temporaryPassword = null;

        $user = DB::transaction(function () use ($existing, $attributes, $email, $institution, $actor, $roles, $sendInvite, $initialPassword, &$temporaryPassword): User {
            $user = $existing;

            if ($user === null) {
                $user = new User([
                    'name' => $attributes['name'],
                    'email' => $email,
                    'mobile' => $attributes['mobile'] ?? null,
                    'username' => $attributes['username'] ?? null,
                    'locale' => $attributes['locale'] ?? 'en',
                    'timezone' => $attributes['timezone'] ?? null,
                ]);

                if ($initialPassword === null && ! $sendInvite) {
                    $initialPassword = $temporaryPassword = $this->generateTemporaryPassword();
                }

                $user->forceFill([
                    'status' => $initialPassword !== null ? UserStatus::Active : UserStatus::Invited,
                    'password' => $initialPassword ?? Str::password(40),
                    'must_change_password' => $initialPassword !== null,
                    'password_changed_at' => $initialPassword !== null ? now() : null,
                    'email_verified_at' => null,
                ])->save();

                $this->securityLogger->log(SecurityEventType::UserCreated, $user, ['invited' => $initialPassword === null]);
            }

            $user->institutions()->syncWithoutDetaching([
                $institution->id => [
                    'status' => 'active',
                    'is_default' => ! $user->institutions()->exists(),
                    'joined_at' => now(),
                ],
            ]);

            foreach ($roles as $role) {
                $this->assignRole->handle($user, $role, $actor);
            }

            return $user;
        });

        if ($existing === null && $user->status === UserStatus::Invited) {
            $this->sendInvitation($user);
        }

        return ['user' => $user->refresh(), 'temporary_password' => $temporaryPassword, 'linked_existing' => $existing !== null];
    }

    public function sendInvitation(User $user): void
    {
        /** @var PasswordBroker $broker */
        $broker = Password::broker('invites');
        $token = $broker->createToken($user);

        $user->notify(new AccountSetupNotification($token));
    }

    private function generateTemporaryPassword(): string
    {
        // Meets every complexity option: upper, lower, number, symbol.
        return Str::password(10, symbols: false).'aA1!'.Str::password(4, letters: false, symbols: false);
    }
}
