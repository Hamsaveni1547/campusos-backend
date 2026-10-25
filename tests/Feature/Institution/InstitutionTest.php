<?php

namespace Tests\Feature\Institution;

use App\Models\User;
use App\Modules\Identity\Enums\MfaMethodType;
use App\Modules\Identity\Models\MfaMethod;
use App\Modules\Identity\Notifications\AccountSetupNotification;
use App\Modules\Identity\Services\TrustedDeviceService;
use App\Modules\Institution\Models\Institution;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InstitutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_provisions_institution_with_admin(): void
    {
        Notification::fake();
        $platform = $this->platformAdmin();
        $university = $this->institution(['type' => 'university']);

        $response = $this->api('POST', 'platform/institutions', $platform, [
            'code' => 'bmsce',
            'name' => 'BMS College of Engineering',
            'type' => 'autonomous_college',
            'parent_id' => $university->ulid,
            'city' => 'Bengaluru',
            'admin' => ['name' => 'Principal Office', 'email' => 'admin@bmsce.example.com'],
        ])->assertCreated()
            ->assertJsonPath('data.code', 'BMSCE')
            ->assertJsonPath('data.parent.id', $university->ulid)
            ->assertJsonPath('meta.admin.email', 'admin@bmsce.example.com');

        $institution = Institution::query()->where('ulid', $response->json('data.id'))->firstOrFail();
        $admin = User::query()->where('email', 'admin@bmsce.example.com')->firstOrFail();

        $this->assertTrue($admin->belongsToInstitution($institution->id));
        $this->assertSame(['institution_admin'], $admin->roleAssignments()->withoutGlobalScopes()->with('role')->get()->pluck('role.name')->all());
        Notification::assertSentTo($admin, AccountSetupNotification::class);

        $this->api('GET', 'platform/institutions?filter[search]=BMS', $platform)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_only_platform_admins_reach_platform_endpoints(): void
    {
        $admin = $this->member($this->institution(), ['institution_admin']);

        $this->api('GET', 'platform/institutions', $admin)->assertForbidden();
    }

    public function test_suspension_signs_everyone_out_and_blocks_access(): void
    {
        $platform = $this->platformAdmin();
        $institution = $this->institution();
        $member = $this->member($institution, ['faculty']);
        $token = $this->login($member);

        $this->api('POST', "platform/institutions/{$institution->ulid}/suspend", $platform, ['reason' => 'Unpaid subscription'])
            ->assertOk()->assertJsonPath('data.status', 'suspended');

        app('auth')->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['login' => $member->email, 'password' => UserFactory::PASSWORD, 'device_name' => 'T'])
            ->assertForbidden()->assertJsonPath('code', 'INSTITUTION_SUSPENDED');

        $this->api('POST', "platform/institutions/{$institution->ulid}/reactivate", $platform)->assertOk()->assertJsonPath('data.status', 'active');
        $this->login($member);
    }

    public function test_institution_admin_updates_profile_but_not_code(): void
    {
        $institution = $this->institution(['code' => 'ORIG']);
        $admin = $this->member($institution, ['institution_admin']);
        $faculty = $this->member($institution, ['faculty']);

        $this->api('GET', 'institution', $faculty)->assertOk()->assertJsonPath('data.code', 'ORIG');
        $this->api('PATCH', 'institution', $faculty, ['name' => 'X'])->assertForbidden();

        $this->api('PATCH', 'institution', $admin, ['name' => 'Renamed College', 'code' => 'NEW', 'website' => 'https://college.example.com'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed College')
            ->assertJsonPath('data.code', 'ORIG');

        $this->assertDatabaseHas('activity_log', ['subject_id' => $institution->id, 'log_name' => 'institution', 'event' => 'updated']);
    }

    public function test_switching_institution_with_header(): void
    {
        $first = $this->institution();
        $second = $this->institution();
        $stranger = $this->institution();
        $person = $this->member($first, ['faculty']);
        $person->institutions()->attach($second->id, ['status' => 'active', 'is_default' => false]);
        $this->grantRole($person, $second, 'institution_admin');

        $this->api('GET', 'institution', $person)->assertOk()->assertJsonPath('data.id', $first->ulid);
        $this->api('GET', 'users', $person)->assertForbidden();

        $this->api('GET', 'institution', $person, [], ['X-Institution' => $second->ulid])->assertForbidden()->assertJsonPath('code', 'MFA_ENROLLMENT_REQUIRED');

        // MFA policy is per institution: the second one still requires it for admins.
        $this->setting($second, 'security', ['mfa_required_roles' => []]);
        $this->api('GET', 'institution', $person, [], ['X-Institution' => $second->ulid])->assertOk()->assertJsonPath('data.id', $second->ulid);
        $this->api('GET', 'users', $person, [], ['X-Institution' => $second->code])->assertOk();

        $this->api('GET', 'institution', $person, [], ['X-Institution' => $stranger->ulid])->assertForbidden()->assertJsonPath('code', 'NOT_A_MEMBER');
        $this->api('GET', 'me/institutions', $person)->assertOk()->assertJsonCount(2, 'data');
    }

    private function platformAdmin(): User
    {
        $user = User::factory()->platformAdmin()->create();
        MfaMethod::query()->create(['user_id' => $user->id, 'type' => MfaMethodType::Email, 'confirmed_at' => now(), 'is_primary' => true]);
        $device = app(TrustedDeviceService::class)->trust($user, 'Test', null);

        $token = $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => UserFactory::PASSWORD, 'device_name' => 'Test', 'trusted_device_token' => $device['token']])
            ->assertOk()->json('data.access_token');

        $this->tokens[$user->id] = $token;

        return $user;
    }
}
