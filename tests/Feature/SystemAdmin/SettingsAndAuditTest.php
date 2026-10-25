<?php

namespace Tests\Feature\SystemAdmin;

use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsAndAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_reads_and_updates_security_settings(): void
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);

        $groups = collect($this->api('GET', 'settings', $admin)->assertOk()->json('data'))->pluck('group');
        $this->assertEqualsCanonicalizing(['security', 'files', 'general', 'hr'], $groups->all());

        $this->api('PUT', 'settings/security', $admin, ['password_min_length' => 4])->assertStatus(422)->assertJsonValidationErrors('password_min_length');

        $this->api('PUT', 'settings/security', $admin, ['password_min_length' => 12, 'unknown_key' => 'x'])
            ->assertOk()
            ->assertJsonFragment(['key' => 'password_min_length', 'value' => 12]);

        $this->assertDatabaseHas('security_events', ['event' => 'settings_changed', 'institution_id' => $institution->id]);
        $this->assertDatabaseMissing('settings', ['key' => 'unknown_key']);

        // The new rule applies straight away.
        $this->api('PUT', 'me/password', $admin, ['current_password' => UserFactory::PASSWORD, 'password' => 'Short@Pw1', 'password_confirmation' => 'Short@Pw1'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_settings_are_isolated_per_institution(): void
    {
        $first = $this->institution();
        $second = $this->institution();
        $adminA = $this->member($first, ['institution_admin']);
        $adminB = $this->member($second, ['institution_admin']);

        $this->api('PUT', 'settings/files', $adminA, ['max_upload_kb' => 2048])->assertOk();

        $this->api('GET', 'settings/files', $adminB)->assertOk()->assertJsonFragment(['key' => 'max_upload_kb', 'value' => 10240]);
    }

    public function test_faculty_cannot_view_settings_or_audit(): void
    {
        $faculty = $this->member($this->institution(), ['faculty']);

        $this->api('GET', 'settings', $faculty)->assertForbidden();
        $this->api('GET', 'audit/activities', $faculty)->assertForbidden();
        $this->api('GET', 'audit/security-events', $faculty)->assertForbidden();
    }

    public function test_audit_trail_records_changes_with_trace_id(): void
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $staff = $this->member($institution, ['staff'], ['name' => 'Old Name']);

        $traceId = 'trace-1234567890';
        $this->api('PATCH', "users/{$staff->ulid}", $admin, ['name' => 'New Name'], ['X-Trace-Id' => $traceId])
            ->assertOk()->assertHeader('X-Trace-Id', $traceId);

        $entry = collect($this->api('GET', 'audit/activities?filter[subject_type]=user', $admin)->assertOk()->json('data'))
            ->firstWhere('trace_id', $traceId);

        $this->assertNotNull($entry);
        $this->assertSame('updated', $entry['event']);
        $this->assertSame('New Name', $entry['changes']['attributes']['name']);
        $this->assertSame('Old Name', $entry['changes']['old']['name']);
        $this->assertSame($admin->ulid, $entry['causer']['id']);

        $this->api('GET', 'audit/activities/export', $admin)->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    }

    public function test_security_events_are_listed_for_the_institution(): void
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $this->postJson('/api/v1/auth/login', ['login' => $admin->email, 'password' => 'bad', 'device_name' => 'T']);

        $events = collect($this->api('GET', 'audit/security-events?filter[event]=login_failed', $admin)->assertOk()->json('data'));

        $this->assertCount(1, $events);
        $this->assertSame('warning', $events->first()['severity']);
    }

    public function test_error_envelope_and_health(): void
    {
        $this->getJson('/api/v1/health')->assertOk()->assertExactJson(['status' => 'ok']);

        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonStructure(['message', 'code', 'trace_id']);
        $this->getJson('/api/v1/does-not-exist')->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');

        $admin = $this->member($this->institution(), ['institution_admin']);
        $this->api('GET', 'admin/health', $admin)->assertOk()->assertJsonPath('data.checks.database.status', 'ok');
        $this->api('GET', 'platform/monitoring/failed-jobs', $admin)->assertForbidden();
    }
}
