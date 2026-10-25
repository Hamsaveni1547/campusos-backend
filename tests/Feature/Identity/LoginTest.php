<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Identity\Enums\MfaMethodType;
use App\Modules\Identity\Models\MfaMethod;
use App\Modules\Identity\Notifications\AccountLockedNotification;
use App\Modules\Identity\Notifications\NewDeviceSignInNotification;
use App\Modules\Identity\Notifications\OneTimeCodeNotification;
use App\Modules\Identity\Services\RecoveryCodeService;
use App\Modules\Identity\Services\TotpService;
use App\Shared\Audit\SecurityEventType;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_sign_in_and_receives_token_and_profile(): void
    {
        $institution = $this->institution();
        $user = $this->member($institution, ['faculty']);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => strtoupper($user->email),
            'password' => UserFactory::PASSWORD,
            'device_name' => 'Chrome on Windows',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.mfa_required', false)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.user.institution.code', $institution->code)
            ->assertJsonPath('data.user.roles', ['faculty']);

        $this->assertStringContainsString('|cos_', (string) $response->json('data.access_token'));
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'event' => SecurityEventType::LoginSucceeded->value]);

        $this->withToken((string) $response->json('data.access_token'))->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->ulid);
    }

    public function test_sign_in_with_username_or_mobile(): void
    {
        $institution = $this->institution();
        $user = $this->member($institution, [], ['username' => 'asha.k', 'mobile' => '9876543210']);

        foreach (['asha.k', '9876543210'] as $login) {
            $this->postJson('/api/v1/auth/login', ['login' => $login, 'password' => UserFactory::PASSWORD, 'device_name' => 'Test'])
                ->assertOk()
                ->assertJsonPath('data.user.id', $user->ulid);
        }
    }

    public function test_wrong_password_and_unknown_account_get_the_same_error(): void
    {
        $user = $this->member($this->institution());

        $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => 'wrong-password', 'device_name' => 'Test'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_CREDENTIALS');

        $this->postJson('/api/v1/auth/login', ['login' => 'nobody@example.com', 'password' => 'wrong-password', 'device_name' => 'Test'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_CREDENTIALS');

        $this->assertSame(1, $user->fresh()->failed_login_attempts);
    }

    public function test_account_locks_after_too_many_failures(): void
    {
        Notification::fake();
        $institution = $this->institution();
        $user = $this->member($institution);
        $this->setting($institution, 'security', ['max_login_attempts' => 3, 'lockout_minutes' => 10]);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => 'bad', 'device_name' => 'Test'])->assertStatus(422);
        }

        $this->assertTrue($user->fresh()->isLocked());
        Notification::assertSentTo($user, AccountLockedNotification::class);

        $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => UserFactory::PASSWORD, 'device_name' => 'Test'])
            ->assertStatus(423)
            ->assertJsonPath('code', 'ACCOUNT_LOCKED');

        $this->travel(11)->minutes();

        $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => UserFactory::PASSWORD, 'device_name' => 'Test'])->assertOk();
    }

    public function test_inactive_and_invited_accounts_cannot_sign_in(): void
    {
        $institution = $this->institution();
        $inactive = $this->member($institution, [], ['status' => 'inactive']);
        $invited = $this->member($institution, [], ['status' => 'invited']);

        $this->postJson('/api/v1/auth/login', ['login' => $inactive->email, 'password' => UserFactory::PASSWORD, 'device_name' => 'Test'])
            ->assertForbidden()->assertJsonPath('code', 'ACCOUNT_INACTIVE');

        $this->postJson('/api/v1/auth/login', ['login' => $invited->email, 'password' => UserFactory::PASSWORD, 'device_name' => 'Test'])
            ->assertForbidden()->assertJsonPath('code', 'ACCOUNT_NOT_SET_UP');
    }

    public function test_members_of_a_suspended_institution_cannot_sign_in(): void
    {
        $institution = $this->institution(['status' => 'suspended']);
        $user = $this->member($institution);

        $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => UserFactory::PASSWORD, 'device_name' => 'Test'])
            ->assertForbidden()->assertJsonPath('code', 'INSTITUTION_SUSPENDED');
    }

    public function test_totp_challenge_flow(): void
    {
        $user = $this->member($this->institution());
        $secret = $this->enableTotp($user);

        $challenge = $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => UserFactory::PASSWORD, 'device_name' => 'Laptop'])
            ->assertOk()
            ->assertJsonPath('data.mfa_required', true)
            ->assertJsonPath('data.methods', ['totp'])
            ->json('data.challenge_token');

        $this->postJson('/api/v1/auth/mfa/verify', ['challenge_token' => $challenge, 'method' => 'totp', 'code' => '000000'])
            ->assertStatus(422)->assertJsonPath('code', 'MFA_INVALID_CODE');

        $response = $this->postJson('/api/v1/auth/mfa/verify', [
            'challenge_token' => $challenge,
            'method' => 'totp',
            'code' => app(TotpService::class)->currentCode($secret),
            'remember_device' => true,
        ])->assertOk()->assertJsonPath('data.mfa_required', false);

        $this->assertNotNull($response->json('data.access_token'));
        $trustedToken = $response->json('data.trusted_device_token');
        $this->assertNotNull($trustedToken);

        // Same code cannot be replayed and the challenge is closed.
        $this->postJson('/api/v1/auth/mfa/verify', ['challenge_token' => $challenge, 'method' => 'totp', 'code' => '123456'])
            ->assertStatus(422)->assertJsonPath('code', 'MFA_CHALLENGE_EXPIRED');

        // A trusted device skips the second factor.
        $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => UserFactory::PASSWORD, 'device_name' => 'Laptop', 'trusted_device_token' => $trustedToken])
            ->assertOk()->assertJsonPath('data.mfa_required', false);
    }

    public function test_email_code_challenge_flow(): void
    {
        Notification::fake();
        $user = $this->member($this->institution());
        MfaMethod::query()->create(['user_id' => $user->id, 'type' => MfaMethodType::Email, 'confirmed_at' => now(), 'is_primary' => true]);

        $challenge = $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => UserFactory::PASSWORD, 'device_name' => 'Phone'])
            ->assertOk()->json('data.challenge_token');

        $this->postJson('/api/v1/auth/mfa/send-code', ['challenge_token' => $challenge, 'method' => 'email'])
            ->assertOk()->assertJsonStructure(['data' => ['sent_to', 'expires_at']]);

        $code = null;
        Notification::assertSentTo($user, OneTimeCodeNotification::class, function (OneTimeCodeNotification $notification) use (&$code): bool {
            $code = $notification->code;

            return true;
        });

        // A second code cannot be requested immediately.
        $this->postJson('/api/v1/auth/mfa/send-code', ['challenge_token' => $challenge, 'method' => 'email'])
            ->assertStatus(429)->assertJsonPath('code', 'OTP_RESEND_TOO_SOON');

        $this->postJson('/api/v1/auth/mfa/verify', ['challenge_token' => $challenge, 'method' => 'email', 'code' => $code])
            ->assertOk()->assertJsonPath('data.mfa_required', false);
    }

    public function test_recovery_code_signs_in_once(): void
    {
        $user = $this->member($this->institution());
        $this->enableTotp($user);
        $codes = app(RecoveryCodeService::class)->regenerate($user);

        $challenge = $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => UserFactory::PASSWORD, 'device_name' => 'Test'])->json('data.challenge_token');
        $this->postJson('/api/v1/auth/mfa/verify', ['challenge_token' => $challenge, 'method' => 'recovery_code', 'code' => strtolower($codes[0])])->assertOk();

        $challenge = $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => UserFactory::PASSWORD, 'device_name' => 'Test'])->json('data.challenge_token');
        $this->postJson('/api/v1/auth/mfa/verify', ['challenge_token' => $challenge, 'method' => 'recovery_code', 'code' => $codes[0]])->assertStatus(422);

        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'event' => SecurityEventType::RecoveryCodeUsed->value]);
    }

    public function test_challenge_closes_after_too_many_wrong_codes(): void
    {
        $user = $this->member($this->institution());
        $this->enableTotp($user);
        $challenge = $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => UserFactory::PASSWORD, 'device_name' => 'Test'])->json('data.challenge_token');

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/auth/mfa/verify', ['challenge_token' => $challenge, 'method' => 'totp', 'code' => '000000'])->assertJsonPath('code', 'MFA_INVALID_CODE');
        }

        $this->postJson('/api/v1/auth/mfa/verify', ['challenge_token' => $challenge, 'method' => 'totp', 'code' => '000000'])->assertJsonPath('code', 'MFA_CHALLENGE_EXHAUSTED');
        $this->postJson('/api/v1/auth/mfa/verify', ['challenge_token' => $challenge, 'method' => 'totp', 'code' => '000000'])->assertJsonPath('code', 'MFA_CHALLENGE_EXPIRED');
    }

    public function test_new_device_alert_after_first_sign_in(): void
    {
        Notification::fake();
        $user = $this->member($this->institution());

        $this->withHeaders(['User-Agent' => 'Browser A'])->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => UserFactory::PASSWORD, 'device_name' => 'A'])->assertOk();
        Notification::assertNotSentTo($user, NewDeviceSignInNotification::class);

        $this->withHeaders(['User-Agent' => 'Browser B'])->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => UserFactory::PASSWORD, 'device_name' => 'B'])->assertOk();
        Notification::assertSentTo($user, NewDeviceSignInNotification::class);
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = $this->member($this->institution());
        $token = $this->login($user);

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_idle_token_expires(): void
    {
        $institution = $this->institution();
        $user = $this->member($institution);
        $this->setting($institution, 'security', ['idle_timeout_minutes' => 30]);
        $token = $this->login($user);

        $this->travel(31)->minutes();
        app('auth')->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_deactivated_user_token_stops_working(): void
    {
        $user = $this->member($this->institution());
        $token = $this->login($user);

        $user->forceFill(['status' => 'inactive'])->save();
        app('auth')->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    private function enableTotp(User $user): string
    {
        $totp = app(TotpService::class);
        $secret = $totp->generateSecret();
        MfaMethod::query()->create(['user_id' => $user->id, 'type' => MfaMethodType::Totp, 'secret' => $secret, 'confirmed_at' => now(), 'is_primary' => true]);

        return $secret;
    }
}
