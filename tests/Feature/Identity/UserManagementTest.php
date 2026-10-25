<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Notifications\AccountSetupNotification;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_lists_only_users_of_their_institution(): void
    {
        $institution = $this->institution();
        $other = $this->institution();
        $admin = $this->member($institution, ['institution_admin'], ['name' => 'Aaron Admin']);
        $this->member($institution, ['faculty'], ['name' => 'Bhavana Faculty']);
        $outsider = $this->member($other, ['faculty'], ['name' => 'Zed Outsider']);

        $response = $this->api('GET', 'users?sort=name', $admin)->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();

        $this->assertSame(['Aaron Admin', 'Bhavana Faculty'], $names);
        $this->assertSame(2, $response->json('meta.total'));

        $this->api('GET', 'users?filter[role]=faculty', $admin)->assertOk()->assertJsonCount(1, 'data');
        $this->api('GET', 'users?filter[search]=bhav', $admin)->assertOk()->assertJsonPath('data.0.name', 'Bhavana Faculty');

        // A user of another institution is not visible, even by id.
        $this->api('GET', 'users/'.$outsider->ulid, $admin)->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $this->api('PATCH', 'users/'.$outsider->ulid, $admin, ['name' => 'Hacked'])->assertNotFound();
        $this->assertSame('Zed Outsider', $outsider->fresh()->name);
    }

    public function test_users_without_permission_cannot_manage_users(): void
    {
        $institution = $this->institution();
        $faculty = $this->member($institution, ['faculty']);

        $this->api('GET', 'users', $faculty)->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $this->api('POST', 'users', $faculty, ['name' => 'X', 'email' => 'x@example.com'])->assertForbidden();
    }

    public function test_create_user_with_role_and_invitation(): void
    {
        Notification::fake();
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $facultyRole = Role::query()->where('name', 'faculty')->firstOrFail();

        $response = $this->api('POST', 'users', $admin, [
            'name' => 'Dr. Meera Rao',
            'email' => 'Meera.Rao@Example.com',
            'mobile' => '9811122233',
            'roles' => [$facultyRole->ulid],
        ])->assertCreated()
            ->assertJsonPath('data.email', 'meera.rao@example.com')
            ->assertJsonPath('data.status', 'invited')
            ->assertJsonPath('data.role_assignments.0.role.name', 'faculty')
            ->assertJsonPath('meta.temporary_password', null);

        $user = User::query()->where('ulid', $response->json('data.id'))->firstOrFail();
        Notification::assertSentTo($user, AccountSetupNotification::class);
        $this->assertTrue($user->belongsToInstitution($institution->id));

        $this->api('POST', 'users', $admin, ['name' => 'Again', 'email' => 'meera.rao@example.com'])
            ->assertStatus(409)->assertJsonPath('code', 'USER_ALREADY_EXISTS');
    }

    public function test_existing_person_is_linked_not_duplicated(): void
    {
        Notification::fake();
        $first = $this->institution();
        $second = $this->institution();
        $person = $this->member($first, ['faculty'], ['email' => 'visiting@example.com']);
        $admin = $this->member($second, ['institution_admin']);

        $this->api('POST', 'users', $admin, ['name' => 'Visiting Faculty', 'email' => 'visiting@example.com'])
            ->assertCreated()
            ->assertJsonPath('data.id', $person->ulid)
            ->assertJsonPath('meta.linked_existing_account', true);

        $this->assertSame(1, User::query()->where('email', 'visiting@example.com')->count());
        $this->assertTrue($person->belongsToInstitution($second->id));
        Notification::assertNothingSentTo($person);
    }

    public function test_temporary_password_option(): void
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);

        $response = $this->api('POST', 'users', $admin, ['name' => 'Clerk', 'email' => 'clerk@example.com', 'send_invite' => false])
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.must_change_password', true);

        $password = $response->json('meta.temporary_password');
        $this->assertNotEmpty($password);

        $this->postJson('/api/v1/auth/login', ['login' => 'clerk@example.com', 'password' => $password, 'device_name' => 'Test'])
            ->assertOk()->assertJsonPath('data.user.pending_requirement', 'PASSWORD_CHANGE_REQUIRED');
    }

    public function test_deactivate_and_reactivate_membership(): void
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $staff = $this->member($institution, ['staff']);
        $staffToken = $this->login($staff);

        $this->api('POST', "users/{$admin->ulid}/deactivate", $admin, ['reason' => 'Self'])->assertForbidden()->assertJsonPath('code', 'SELF_DEACTIVATION');
        $this->api('POST', "users/{$staff->ulid}/deactivate", $admin, [])->assertStatus(422);

        $this->api('POST', "users/{$staff->ulid}/deactivate", $admin, ['reason' => 'Left the institution'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.membership_status', 'inactive');

        app('auth')->forgetGuards();
        $this->withToken($staffToken)->getJson('/api/v1/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['login' => $staff->email, 'password' => UserFactory::PASSWORD, 'device_name' => 'T'])->assertForbidden();

        $this->api('POST', "users/{$staff->ulid}/activate", $admin)->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertSame(UserStatus::Active, $staff->fresh()->status);
    }

    public function test_deactivation_in_one_institution_keeps_access_to_another(): void
    {
        $first = $this->institution();
        $second = $this->institution();
        $admin = $this->member($first, ['institution_admin']);
        $person = $this->member($first, ['faculty']);
        $person->institutions()->attach($second->id, ['status' => 'active', 'is_default' => false]);

        $this->api('POST', "users/{$person->ulid}/deactivate", $admin, ['reason' => 'Contract ended'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.membership_status', 'inactive');

        $this->assertFalse($person->fresh()->belongsToInstitution($first->id));
        $this->assertTrue($person->fresh()->belongsToInstitution($second->id));
    }

    public function test_unlock_and_reset_password_by_admin(): void
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $staff = $this->member($institution, ['staff'], ['locked_until' => now()->addHour(), 'failed_login_attempts' => 3]);

        $this->api('POST', "users/{$staff->ulid}/unlock", $admin)->assertOk()->assertJsonPath('data.is_locked', false);

        $password = $this->api('POST', "users/{$staff->ulid}/reset-password", $admin, ['mode' => 'temporary_password'])
            ->assertOk()->json('data.temporary_password');

        $this->assertTrue($staff->fresh()->must_change_password);
        $this->login($staff->fresh(), $password);
    }

    public function test_cannot_reset_credentials_of_a_more_privileged_user(): void
    {
        $institution = $this->institution();
        $hr = $this->member($institution, ['hr_officer']);
        $this->grantRole($hr, $institution, 'staff');
        $role = Role::query()->where('name', 'hr_officer')->firstOrFail();
        $role->givePermissionTo('identity.users.reset_password');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = $this->member($institution, ['institution_admin']);

        $this->api('POST', "users/{$admin->ulid}/reset-password", $hr, ['mode' => 'temporary_password'])
            ->assertForbidden()->assertJsonPath('code', 'PRIVILEGE_ESCALATION');
    }

    public function test_reset_mfa_removes_methods(): void
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $staff = $this->member($institution, ['staff']);
        $staff->mfaMethods()->create(['type' => 'email', 'confirmed_at' => now(), 'is_primary' => true]);

        $this->api('POST', "users/{$staff->ulid}/reset-mfa", $admin)->assertOk();
        $this->assertFalse($staff->fresh()->hasMfaEnabled());
    }

    public function test_csv_import_validates_then_commits(): void
    {
        Notification::fake();
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);

        $csv = "name,email,mobile,roles\nRavi Teja,ravi@example.com,9000000001,faculty\nSita,sita@example.com,,faculty|staff\n";
        $bad = "name,email\nNo Email,\nDup,dup@example.com\nDup Again,dup@example.com\n";

        $fileId = $this->upload($admin, $bad);
        $import = $this->api('POST', 'imports', $admin, ['type' => 'users', 'file_id' => $fileId])->assertStatus(202)->json('data');
        $import = $this->api('GET', 'imports/'.$import['id'], $admin)->assertOk()->json('data');

        $this->assertSame('invalid', $import['status']);
        $this->assertSame(3, $import['total_rows']);
        $this->assertSame(1, $import['valid_rows']);
        $this->api('POST', 'imports/'.$import['id'].'/commit', $admin)->assertStatus(409);

        $fileId = $this->upload($admin, $csv);
        $import = $this->api('POST', 'imports', $admin, ['type' => 'users', 'file_id' => $fileId])->json('data');
        $this->assertSame('validated', $this->api('GET', 'imports/'.$import['id'], $admin)->json('data.status'));

        $this->api('POST', 'imports/'.$import['id'].'/commit', $admin)->assertStatus(202);
        $done = $this->api('GET', 'imports/'.$import['id'], $admin)->assertOk()->json('data');

        $this->assertSame('completed', $done['status']);
        $this->assertSame(2, $done['processed_rows']);

        $sita = User::query()->where('email', 'sita@example.com')->firstOrFail();
        $this->assertTrue($sita->belongsToInstitution($institution->id));
        $this->assertSame(2, $sita->roleAssignments()->count());
    }

    public function test_import_template_requires_permission(): void
    {
        $institution = $this->institution();
        $faculty = $this->member($institution, ['faculty']);
        $admin = $this->member($institution, ['institution_admin']);

        $this->api('GET', 'imports/templates/users', $faculty)->assertForbidden();
        $this->api('GET', 'imports/templates/users', $admin)->assertOk()->assertJsonPath('data.required', ['name', 'email']);
    }

    private function upload(User $user, string $contents): string
    {
        $file = UploadedFile::fake()->createWithContent('users.csv', $contents);

        return (string) $this->api('POST', 'files', $user, ['file' => $file, 'purpose' => 'import'])->assertCreated()->json('data.id');
    }
}
