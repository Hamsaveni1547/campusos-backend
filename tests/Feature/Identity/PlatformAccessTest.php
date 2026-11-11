<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Identity\Actions\Roles\SyncSystemPermissions;
use App\Modules\Identity\Enums\MfaMethodType;
use App\Modules\Identity\Models\MfaMethod;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Services\TrustedDeviceService;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAccessTest extends TestCase
{
    use RefreshDatabase;

    private function platformAdmin(): User
    {
        $user = User::factory()->platformAdmin()->create();
        MfaMethod::query()->create(['user_id' => $user->id, 'type' => MfaMethodType::Email, 'confirmed_at' => now(), 'is_primary' => true]);
        $device = app(TrustedDeviceService::class)->trust($user, 'Test', null);
        $this->tokens[$user->id] = $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => UserFactory::PASSWORD, 'device_name' => 'Test', 'trusted_device_token' => $device['token']])
            ->assertOk()->json('data.access_token');

        return $user;
    }

    public function test_role_permission_matrix_is_saved_kept_by_sync_and_reset(): void
    {
        $platform = $this->platformAdmin();
        $institution = $this->institution();
        $hr = $this->member($institution, ['hr_officer'], ['email' => 'hr@example.edu']);

        $this->api('GET', 'platform/access/modules', $platform)->assertOk()->assertJsonFragment(['key' => 'payroll', 'core' => false]);
        $this->api('GET', 'platform/access/permissions', $platform)->assertOk()->assertJsonFragment(['name' => 'payroll.runs.approve']);
        $roles = collect($this->api('GET', 'platform/access/roles', $platform)->assertOk()->json('data'));
        $hrRole = $roles->firstWhere('name', 'hr_officer');
        $this->assertSame(1, $hrRole['users']);

        $current = $this->api('GET', "platform/access/roles/{$hrRole['id']}", $platform)->assertOk()->json('data.permissions');
        $this->assertContains('payroll.reports.view', $current);
        $this->api('GET', 'payroll-runs', $hr)->assertOk();

        // Take payroll away from HR: effective at once, and the sync keeps the edit.
        $this->api('PUT', "platform/access/roles/{$hrRole['id']}/permissions", $platform, ['permissions' => array_values(array_diff($current, ['payroll.reports.view']))])->assertOk();
        $this->api('GET', 'payroll-runs', $hr)->assertForbidden();
        app(SyncSystemPermissions::class)->handle();
        $this->assertFalse(Role::query()->where('name', 'hr_officer')->firstOrFail()->hasPermissionTo('payroll.reports.view'));
        $this->api('GET', "platform/access/roles/{$hrRole['id']}", $platform)->assertJsonPath('data.is_customised', true);

        $this->api('POST', "platform/access/roles/{$hrRole['id']}/reset", $platform)->assertOk();
        $this->api('GET', 'payroll-runs', $hr)->assertOk();

        // Guard rails
        $adminRole = $roles->firstWhere('name', 'institution_admin');
        $this->api('PUT', "platform/access/roles/{$adminRole['id']}/permissions", $platform, ['permissions' => []])->assertUnprocessable()->assertJsonPath('code', 'LOCKOUT');
        $this->api('PUT', "platform/access/roles/{$hrRole['id']}/permissions", $platform, ['permissions' => ['no.such.permission']])->assertUnprocessable();
        $this->api('GET', 'platform/access/roles', $hr)->assertForbidden();
    }

    public function test_switching_a_module_off_hides_and_blocks_it(): void
    {
        $platform = $this->platformAdmin();
        $institution = $this->institution();
        $hr = $this->member($institution, ['hr_officer'], ['email' => 'hr@example.edu']);

        $this->api('PUT', "platform/institutions/{$institution->ulid}/modules", $platform, ['modules' => ['payroll' => false, 'identity' => false]])->assertOk();
        $modules = collect($this->api('GET', "platform/institutions/{$institution->ulid}/modules", $platform)->json('data'))->keyBy('key');
        $this->assertFalse($modules['payroll']['enabled']);
        $this->assertTrue($modules['identity']['enabled'], 'core modules stay on');

        $this->api('GET', 'payroll-runs', $hr)->assertForbidden();
        $this->assertNotContains('payroll.reports.view', $this->api('GET', 'me', $hr)->json('data.permissions'));
        $this->api('GET', 'employees', $hr)->assertOk();

        $this->api('PUT', "platform/institutions/{$institution->ulid}/modules", $platform, ['modules' => ['payroll' => true]])->assertOk();
        $this->api('GET', 'payroll-runs', $hr)->assertOk();
    }
}
