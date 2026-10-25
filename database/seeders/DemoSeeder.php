<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Identity\Actions\Roles\AssignRole;
use App\Modules\Identity\Authorization\SystemRole;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\Role;
use App\Modules\Institution\Enums\InstitutionType;
use App\Modules\Institution\Models\Institution;
use App\Shared\Settings\SettingsRepository;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Database\Seeder;

/**
 * Local/demo data for frontend development: one institution and one user per role.
 *
 * Every demo account uses the password in DEMO_PASSWORD below. MFA is switched
 * off for the demo institution so sign-in is quick; never use this in production.
 */
class DemoSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'Campus@Demo2026';

    public const INSTITUTION_CODE = 'DEMO';

    public function run(CurrentInstitution $currentInstitution, AssignRole $assignRole, SettingsRepository $settings): void
    {
        $platformAdmin = $this->user('Platform Admin', 'platform.admin@campusos.test', isPlatformAdmin: true);

        $institution = Institution::query()->firstOrCreate(
            ['code' => self::INSTITUTION_CODE],
            [
                'name' => 'CampusOS Demo College of Engineering',
                'short_name' => 'CDCE',
                'type' => InstitutionType::College,
                'email' => 'office@demo.campusos.test',
                'city' => 'Bengaluru',
                'state' => 'Karnataka',
                'country' => 'IN',
                'timezone' => 'Asia/Kolkata',
                'currency' => 'INR',
                'established_year' => 2001,
            ],
        );

        $currentInstitution->runAs($institution, function () use ($institution, $assignRole, $settings, $platformAdmin): void {
            $settings->update('security', ['mfa_required_roles' => []], $institution->id);

            foreach (SystemRole::cases() as $systemRole) {
                $email = str_replace('_', '.', $systemRole->value).'@demo.campusos.test';
                $user = $this->user($systemRole->displayName().' (Demo)', $email);

                $user->institutions()->syncWithoutDetaching([$institution->id => ['status' => 'active', 'is_default' => true, 'joined_at' => now()]]);

                $role = Role::query()->whereNull('institution_id')->where('name', $systemRole->value)->firstOrFail();

                if (! $user->roleAssignments()->effective()->where('role_id', $role->id)->exists()) {
                    $assignRole->handle($user, $role, $platformAdmin, reason: 'Demo data');
                }
            }
        });

        $this->command?->info('Demo institution "'.self::INSTITUTION_CODE.'" seeded. Accounts: {role}@demo.campusos.test (e.g. institution.admin@demo.campusos.test), platform.admin@campusos.test.');
    }

    private function user(string $name, string $email, bool $isPlatformAdmin = false): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);

        if (! $user->exists) {
            $user->fill(['name' => $name]);
            $user->forceFill([
                'password' => self::DEMO_PASSWORD,
                'status' => UserStatus::Active,
                'is_platform_admin' => $isPlatformAdmin,
                'email_verified_at' => now(),
                'password_changed_at' => now(),
            ])->save();
        }

        return $user;
    }
}
