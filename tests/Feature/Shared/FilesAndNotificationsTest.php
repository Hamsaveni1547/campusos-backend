<?php

namespace Tests\Feature\Shared;

use App\Modules\Identity\Notifications\NewDeviceSignInNotification;
use App\Shared\Files\FileAccessLog;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FilesAndNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_and_download_with_signed_link(): void
    {
        Storage::fake('local');
        $institution = $this->institution();
        $owner = $this->member($institution, ['faculty']);
        $colleague = $this->member($institution, ['faculty']);

        $file = $this->api('POST', 'files', $owner, ['file' => UploadedFile::fake()->create('marks.pdf', 120, 'application/pdf'), 'purpose' => 'document'])
            ->assertCreated()
            ->assertJsonPath('data.classification', 'confidential')
            ->assertJsonPath('data.is_attached', false)
            ->json('data');

        $url = $this->api('GET', "files/{$file['id']}/download-url", $owner)->assertOk()->json('data.url');
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(1, FileAccessLog::query()->count(), 'Confidential downloads are logged');

        $this->get($url.'x')->assertForbidden();
        $this->api('GET', "files/{$file['id']}", $colleague)->assertNotFound();
        $this->api('DELETE', "files/{$file['id']}", $owner)->assertOk();
    }

    public function test_upload_rules(): void
    {
        Storage::fake('local');
        $institution = $this->institution();
        $user = $this->member($institution);

        $this->api('POST', 'files', $user, ['file' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'), 'purpose' => 'document'])
            ->assertStatus(422)->assertJsonPath('code', 'FILE_TYPE_NOT_ALLOWED');

        $this->setting($institution, 'files', ['max_upload_kb' => 100]);
        $this->api('POST', 'files', $user, ['file' => UploadedFile::fake()->create('big.pdf', 200, 'application/pdf'), 'purpose' => 'document'])
            ->assertStatus(422)->assertJsonPath('code', 'FILE_TOO_LARGE');

        $this->api('POST', 'files', $user, ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), 'purpose' => 'nope'])
            ->assertStatus(422)->assertJsonValidationErrors('purpose');
    }

    public function test_avatar_upload_and_profile(): void
    {
        Storage::fake('local');
        $institution = $this->institution();
        $user = $this->member($institution);
        $colleague = $this->member($institution);

        $fileId = $this->api('POST', 'files', $user, ['file' => UploadedFile::fake()->image('me.png'), 'purpose' => 'avatar'])->json('data.id');

        $this->api('PUT', 'me', $user, ['avatar_file_id' => $fileId, 'timezone' => 'Asia/Kolkata', 'locale' => 'kn'])
            ->assertOk()
            ->assertJsonPath('data.avatar_file_id', $fileId)
            ->assertJsonPath('data.locale', 'kn');

        $this->api('GET', "files/{$fileId}", $colleague)->assertOk();
    }

    public function test_in_app_notifications_and_preferences(): void
    {
        $institution = $this->institution();
        $user = $this->member($institution);
        app(CurrentInstitution::class)->runAs($institution, fn () => $user->notifyNow(new NewDeviceSignInNotification(['device' => 'Firefox', 'ip_address' => '10.0.0.1']), ['database']));

        $this->api('GET', 'me/notifications/unread-count', $user)->assertOk()->assertJsonPath('data.unread', 1);

        $notification = $this->api('GET', 'me/notifications?filter[unread]=1', $user)->assertOk()
            ->assertJsonPath('data.0.category', 'security')
            ->assertJsonPath('data.0.title', 'New sign-in to your account')
            ->json('data.0');

        $this->assertStringContainsString('Firefox', $notification['body']);

        $this->api('POST', "me/notifications/{$notification['id']}/read", $user)->assertOk();
        $this->api('GET', 'me/notifications/unread-count', $user)->assertJsonPath('data.unread', 0);

        $matrix = collect($this->api('GET', 'me/notification-preferences', $user)->assertOk()->json('data'))->keyBy('category');
        $this->assertTrue($matrix['security']['mandatory']);

        $updated = collect($this->api('PUT', 'me/notification-preferences', $user, ['preferences' => [
            'general' => ['mail' => false],
            'security' => ['mail' => false],
        ]])->assertOk()->json('data'))->keyBy('category');

        $this->assertFalse($updated['general']['channels']['mail']);
        $this->assertTrue($updated['security']['channels']['mail'], 'Mandatory categories stay on');

        $this->api('PUT', 'me/notification-preferences', $user, ['preferences' => ['bogus' => ['mail' => true]]])->assertStatus(422);
    }

    public function test_institution_can_customise_templates(): void
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);

        $keys = collect($this->api('GET', 'notification-templates', $admin)->assertOk()->json('data'))->pluck('key');
        $this->assertContains('identity.password_reset', $keys);

        $this->api('PUT', 'notification-templates/identity.security_alert.new_device/mail', $admin, [
            'subject' => 'Sign-in alert from {{ app_name }}',
            'body' => 'Dear {{ name }}, new sign-in from {{ device }} {{ secret_thing }}.',
        ])->assertStatus(422)->assertJsonPath('code', 'UNKNOWN_PLACEHOLDERS');

        $this->api('PUT', 'notification-templates/identity.security_alert.new_device/database', $admin, [
            'subject' => 'Heads up!',
            'body' => 'Dear {{ name }}, someone signed in from {{ device }}.',
        ])->assertOk();

        $user = $this->member($institution);
        app(CurrentInstitution::class)->runAs($institution, fn () => $user->notifyNow(new NewDeviceSignInNotification(['device' => 'Edge']), ['database']));

        $this->api('GET', 'me/notifications', $user)->assertJsonPath('data.0.title', 'Heads up!')
            ->assertJsonPath('data.0.body', "Dear {$user->name}, someone signed in from Edge.");

        $this->api('DELETE', 'notification-templates/identity.security_alert.new_device/database', $admin)->assertOk();
        $this->api('GET', 'notification-deliveries', $admin)->assertOk();
    }
}
