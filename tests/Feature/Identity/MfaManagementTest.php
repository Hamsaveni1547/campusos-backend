<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Notifications\MfaChangedNotification;
use App\Modules\Identity\Notifications\OneTimeCodeNotification;
use App\Modules\Identity\Services\TotpService;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MfaManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_requiring_mfa_forces_enrolment(): void
    {
        Notification::fake();
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin'], withoutMfaRequirement: false);

        $this->api('GET', 'users', $admin)->assertForbidden()->assertJsonPath('code', 'MFA_ENROLLMENT_REQUIRED');
        $this->api('GET', 'me', $admin)->assertOk()
            ->assertJsonPath('data.pending_requirement', 'MFA_ENROLLMENT_REQUIRED')
            ->assertJsonPath('data.mfa.required', true);

        $this->api('POST', 'me/mfa/totp', $admin, [])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $setup = $this->api('POST', 'me/mfa/totp', $admin, ['current_password' => UserFactory::PASSWORD])
            ->assertOk()
            ->assertJsonStructure(['data' => ['secret', 'otpauth_uri', 'qr_svg']])
            ->json('data');

        $this->api('POST', 'me/mfa/totp/confirm', $admin, ['code' => '000000'])->assertStatus(422)->assertJsonPath('code', 'INVALID_CODE');

        $confirmed = $this->api('POST', 'me/mfa/totp/confirm', $admin, ['code' => app(TotpService::class)->currentCode($setup['secret'])])
            ->assertOk()
            ->assertJsonPath('data.method.type', 'totp')
            ->assertJsonPath('data.method.is_primary', true)
            ->json('data');

        $this->assertCount(10, $confirmed['recovery_codes']);
        Notification::assertSentTo($admin, MfaChangedNotification::class);

        $this->api('GET', 'users', $admin)->assertOk();

        // Cannot remove the only method while the role requires MFA.
        $methodId = $confirmed['method']['id'];
        $this->api('DELETE', 'me/mfa/'.$methodId, $admin, ['current_password' => UserFactory::PASSWORD])
            ->assertStatus(409)->assertJsonPath('code', 'MFA_REQUIRED_BY_ROLE');
    }

    public function test_email_method_enrolment_and_removal(): void
    {
        Notification::fake();
        $user = $this->member($this->institution());

        $this->api('POST', 'me/mfa/code-method', $user, ['type' => 'email', 'current_password' => UserFactory::PASSWORD])
            ->assertOk()->assertJsonStructure(['data' => ['sent_to']]);

        $code = null;
        Notification::assertSentTo($user, OneTimeCodeNotification::class, function (OneTimeCodeNotification $notification) use (&$code): bool {
            $code = $notification->code;

            return true;
        });

        $methodId = $this->api('POST', 'me/mfa/code-method/confirm', $user, ['type' => 'email', 'code' => $code])
            ->assertOk()->json('data.method.id');

        $this->api('GET', 'me/mfa', $user)->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.recovery_codes_remaining', 10);

        $this->api('DELETE', 'me/mfa/'.$methodId, $user, ['current_password' => UserFactory::PASSWORD])->assertOk();

        $this->api('GET', 'me/mfa', $user)->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.recovery_codes_remaining', 0);
    }

    public function test_sms_enrolment_verifies_and_saves_mobile(): void
    {
        Notification::fake();
        $user = $this->member($this->institution(), [], ['mobile' => null]);

        $this->api('POST', 'me/mfa/code-method', $user, ['type' => 'sms', 'mobile' => '9998887776', 'current_password' => UserFactory::PASSWORD])
            ->assertOk()->assertJsonPath('data.sent_to', '******7776');

        $code = null;
        Notification::assertSentTo($user, OneTimeCodeNotification::class, function (OneTimeCodeNotification $notification, array $channels) use (&$code): bool {
            $code = $notification->code;

            return $channels === ['sms'];
        });

        $this->api('POST', 'me/mfa/code-method/confirm', $user, ['type' => 'sms', 'code' => $code])->assertOk();

        $this->assertSame('9998887776', $user->fresh()->mobile);
        $this->assertNotNull($user->fresh()->mobile_verified_at);
    }

    public function test_recovery_codes_can_be_regenerated(): void
    {
        Notification::fake();
        $user = $this->member($this->institution());

        $this->api('POST', 'me/mfa/recovery-codes', $user, ['current_password' => UserFactory::PASSWORD])
            ->assertStatus(409)->assertJsonPath('code', 'MFA_NOT_ENABLED');

        $secret = $this->api('POST', 'me/mfa/totp', $user, ['current_password' => UserFactory::PASSWORD])->json('data.secret');
        $first = $this->api('POST', 'me/mfa/totp/confirm', $user, ['code' => app(TotpService::class)->currentCode($secret)])->json('data.recovery_codes');

        $second = $this->api('POST', 'me/mfa/recovery-codes', $user, ['current_password' => UserFactory::PASSWORD])->assertOk()->json('data.recovery_codes');

        $this->assertCount(10, $second);
        $this->assertEmpty(array_intersect($first, $second));
    }
}
