<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Notifications\AccountSetupNotification;
use App\Modules\Identity\Notifications\OneTimeCodeNotification;
use App\Modules\Identity\Notifications\PasswordChangedNotification;
use App\Modules\Identity\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_applicant_registers_verifies_email_and_gets_applicant_role(): void
    {
        Notification::fake();
        $institution = $this->institution(['code' => 'RVCE']);

        $this->postJson('/api/v1/auth/register', [
            'institution' => 'rvce',
            'name' => 'Asha Kumar',
            'email' => 'asha@example.com',
            'mobile' => '9876501234',
            'password' => 'Strong@Pass1',
            'password_confirmation' => 'Strong@Pass1',
            'accept_terms' => true,
        ])->assertCreated()->assertJsonPath('data.status', 'pending_verification');

        $user = User::query()->where('email', 'asha@example.com')->firstOrFail();
        $code = null;
        Notification::assertSentTo($user, OneTimeCodeNotification::class, function (OneTimeCodeNotification $notification) use (&$code): bool {
            $code = $notification->code;

            return true;
        });

        $token = $this->postJson('/api/v1/auth/login', ['login' => 'asha@example.com', 'password' => 'Strong@Pass1', 'device_name' => 'Phone'])
            ->assertOk()
            ->assertJsonPath('data.user.pending_requirement', 'EMAIL_VERIFICATION_REQUIRED')
            ->json('data.access_token');

        $this->withToken($token)->getJson('/api/v1/me/sessions')->assertForbidden()->assertJsonPath('code', 'EMAIL_VERIFICATION_REQUIRED');

        $this->withToken($token)->postJson('/api/v1/auth/verify-email', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('data.email_verified', true)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.roles', ['applicant']);

        $this->assertSame($institution->id, $user->fresh()->defaultInstitution()?->id);
        $this->withToken($token)->getJson('/api/v1/me/sessions')->assertOk();
    }

    public function test_registration_validates_password_policy_and_closed_registration(): void
    {
        $institution = $this->institution(['code' => 'ABC']);

        $this->postJson('/api/v1/auth/register', [
            'institution' => 'ABC', 'name' => 'X', 'email' => 'x@example.com', 'mobile' => '9876501299',
            'password' => 'weak', 'password_confirmation' => 'weak', 'accept_terms' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->setting($institution, 'security', ['allow_applicant_registration' => false]);

        $this->postJson('/api/v1/auth/register', [
            'institution' => 'ABC', 'name' => 'X', 'email' => 'x@example.com', 'mobile' => '9876501299',
            'password' => 'Strong@Pass1', 'password_confirmation' => 'Strong@Pass1', 'accept_terms' => true,
        ])->assertForbidden()->assertJsonPath('code', 'REGISTRATION_CLOSED');
    }

    public function test_forgot_and_reset_password(): void
    {
        Notification::fake();
        $user = $this->member($this->institution());
        $oldToken = $this->login($user);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'unknown@example.com'])->assertOk();

        $resetToken = null;
        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use (&$resetToken): bool {
            $resetToken = $notification->token;

            return true;
        });

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email, 'token' => $resetToken,
            'password' => UserFactory::PASSWORD, 'password_confirmation' => UserFactory::PASSWORD,
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email, 'token' => $resetToken,
            'password' => 'Brand@NewPass9', 'password_confirmation' => 'Brand@NewPass9',
        ])->assertOk();

        Notification::assertSentTo($user, PasswordChangedNotification::class);
        $this->assertSame(0, $user->tokens()->count(), 'Reset signs out every device');

        app('auth')->forgetGuards();
        $this->withToken($oldToken)->getJson('/api/v1/me')->assertUnauthorized();
        $this->login($user->fresh(), 'Brand@NewPass9');

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email, 'token' => $resetToken,
            'password' => 'Another@Pass9', 'password_confirmation' => 'Another@Pass9',
        ])->assertStatus(422)->assertJsonPath('code', 'INVALID_RESET_TOKEN');
    }

    public function test_invited_user_sets_password_with_setup_link(): void
    {
        Notification::fake();
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);

        $this->api('POST', 'users', $admin, ['name' => 'New Faculty', 'email' => 'new.faculty@example.com'])->assertCreated()
            ->assertJsonPath('data.status', 'invited');

        $invited = User::query()->where('email', 'new.faculty@example.com')->firstOrFail();
        $token = null;
        Notification::assertSentTo($invited, AccountSetupNotification::class, function (AccountSetupNotification $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        $this->postJson('/api/v1/auth/setup-account', [
            'email' => 'new.faculty@example.com', 'token' => $token,
            'password' => 'First@Pass123', 'password_confirmation' => 'First@Pass123',
        ])->assertOk();

        $this->assertSame(UserStatus::Active, $invited->fresh()->status);
        $this->assertNotNull($invited->fresh()->email_verified_at);
        $this->login($invited->fresh(), 'First@Pass123');
    }

    public function test_forced_password_change_blocks_until_changed(): void
    {
        Notification::fake();
        $institution = $this->institution();
        $user = $this->member($institution, ['institution_admin'], ['must_change_password' => true]);

        $this->api('GET', 'users', $user)->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->api('GET', 'me', $user)->assertOk()->assertJsonPath('data.pending_requirement', 'PASSWORD_CHANGE_REQUIRED');

        $this->api('PUT', 'me/password', $user, [
            'current_password' => 'wrong', 'password' => 'Changed@Pass1', 'password_confirmation' => 'Changed@Pass1',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->api('PUT', 'me/password', $user, [
            'current_password' => UserFactory::PASSWORD, 'password' => 'Changed@Pass1', 'password_confirmation' => 'Changed@Pass1',
        ])->assertOk();

        $this->api('GET', 'users', $user)->assertOk();
    }

    public function test_password_change_signs_out_other_devices_only(): void
    {
        Notification::fake();
        $user = $this->member($this->institution());
        $other = $this->login($user, device: 'Other');

        $this->api('PUT', 'me/password', $user, [
            'current_password' => UserFactory::PASSWORD, 'password' => 'Changed@Pass1', 'password_confirmation' => 'Changed@Pass1',
        ])->assertOk();

        $this->assertSame(1, $user->tokens()->count());
        app('auth')->forgetGuards();
        $this->withToken($other)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_password_history_prevents_reuse(): void
    {
        Notification::fake();
        $user = $this->member($this->institution());

        $this->api('PUT', 'me/password', $user, [
            'current_password' => UserFactory::PASSWORD, 'password' => 'Second@Pass1', 'password_confirmation' => 'Second@Pass1',
        ])->assertOk();

        $this->api('PUT', 'me/password', $user, [
            'current_password' => 'Second@Pass1', 'password' => UserFactory::PASSWORD, 'password_confirmation' => UserFactory::PASSWORD,
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_sessions_can_be_listed_and_revoked(): void
    {
        $user = $this->member($this->institution());
        $this->login($user, device: 'Tablet');

        $sessions = $this->api('GET', 'me/sessions', $user)->assertOk()->json('data');
        $this->assertCount(2, $sessions);

        $tablet = collect($sessions)->firstWhere('device_name', 'Tablet');
        $this->assertFalse($tablet['is_current']);

        $this->api('DELETE', 'me/sessions/'.$tablet['id'], $user)->assertOk();
        $this->assertSame(1, $user->tokens()->count());

        $this->login($user, device: 'Another');
        $this->api('DELETE', 'me/sessions', $user)->assertOk();
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_reset_link_is_not_sent_for_inactive_accounts(): void
    {
        Notification::fake();
        $user = $this->member($this->institution(), [], ['status' => 'inactive']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

        Notification::assertNothingSentTo($user);
        $this->assertFalse(Password::broker()->tokenExists($user, 'x'));
    }
}
