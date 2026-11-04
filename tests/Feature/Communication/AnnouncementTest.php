<?php

namespace Tests\Feature\Communication;

use App\Models\User;
use App\Modules\Communication\Notifications\AnnouncementNotification;
use App\Modules\Identity\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private function activate(string $email): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        $user->forceFill(['password' => Hash::make(UserFactory::PASSWORD), 'status' => UserStatus::Active, 'email_verified_at' => now(), 'must_change_password' => false])->save();

        return $user;
    }

    public function test_publish_to_section_read_acknowledge_stats_and_withdraw(): void
    {
        Notification::fake();
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $faculty = $this->member($institution, ['faculty'], ['email' => 'teacher@example.edu']);

        $department = $this->api('POST', 'departments', $admin, ['code' => 'CSE', 'name' => 'CSE'])->json('data');
        $programme = $this->api('POST', 'programmes', $admin, ['department_id' => $department['id'], 'code' => 'BE-CSE', 'name' => 'B.E. CSE', 'level' => 'ug', 'duration_years' => 4])->json('data');
        $batch = $this->api('POST', 'batches', $admin, ['programme_id' => $programme['id'], 'admission_year' => 2026, 'sections' => ['A', 'B']])->json('data');
        [$sectionA, $sectionB] = array_column($batch['sections'], 'id');

        foreach ([['asha', $sectionA], ['bala', $sectionA], ['chetan', $sectionB]] as [$name, $section]) {
            $this->api('POST', 'students', $admin, ['name' => ucfirst($name), 'email' => "{$name}@example.edu", 'programme_id' => $programme['id'], 'admission_type' => 'cet', 'admitted_on' => '2026-08-01', 'batch_id' => $batch['id'], 'section_id' => $section, 'send_invite' => false])->assertCreated();
        }
        $asha = $this->activate('asha@example.edu');
        $chetan = $this->activate('chetan@example.edu');

        $payload = ['title' => 'Lab moved to Friday', 'body' => 'The DBMS lab moves to Friday 2 pm this week.', 'category' => 'academic', 'priority' => 'important', 'requires_ack' => true];

        // A teacher cannot post to everyone.
        $this->api('POST', 'announcements', $faculty, [...$payload, 'audience_type' => 'everyone'])->assertUnprocessable();
        $this->api('GET', 'announcements/audiences', $faculty)->assertOk()->assertJsonPath('data.types', ['departments', 'programmes', 'batches', 'sections']);

        $id = $this->api('POST', 'announcements', $faculty, [...$payload, 'audience_type' => 'sections', 'audience_ids' => [$sectionA]])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
        $this->api('PUT', "announcements/{$id}", $faculty, [...$payload, 'title' => 'DBMS lab moved to Friday', 'audience_type' => 'sections', 'audience_ids' => [$sectionA]])->assertOk()->assertJsonPath('data.audience', 'BE-CSE 2026 A');

        $this->api('POST', "announcements/{$id}/publish", $faculty)->assertOk()->assertJsonPath('data.recipients', 2);
        Notification::assertSentTo($asha, AnnouncementNotification::class);
        Notification::assertNotSentTo($chetan, AnnouncementNotification::class);
        $this->api('PUT', "announcements/{$id}", $faculty, [...$payload, 'audience_type' => 'sections', 'audience_ids' => [$sectionA]])->assertUnprocessable()->assertJsonPath('code', 'NOT_DRAFT');

        // The student reads and acknowledges; the other section never sees it.
        $this->api('GET', 'me/announcements?filter[unread]=1', $asha)->assertOk()->assertJsonPath('data.0.title', 'DBMS lab moved to Friday')->assertJsonMissingPath('data.0.audience');
        $this->api('GET', "announcements/{$id}", $asha)->assertOk()->assertJsonPath('can_manage', null);
        $this->api('GET', 'me/announcements?filter[unread]=1', $asha)->assertOk()->assertJsonCount(0, 'data');
        $this->api('POST', "announcements/{$id}/acknowledge", $asha)->assertOk();
        $this->api('GET', 'me/announcements', $chetan)->assertOk()->assertJsonCount(0, 'data');
        $this->api('GET', "announcements/{$id}", $chetan)->assertNotFound();

        $this->api('GET', "announcements/{$id}/read-stats", $faculty)->assertOk()
            ->assertJsonPath('data.recipients', 2)->assertJsonPath('data.read', 1)->assertJsonPath('data.acknowledged', 1)->assertJsonPath('data.pending.0.email', 'bala@example.edu');
        $this->api('GET', "announcements/{$id}/read-stats", $asha)->assertForbidden();

        // Admin sees all announcements and can withdraw; withdrawn ones leave the feed.
        $this->api('GET', 'announcements', $admin)->assertOk()->assertJsonPath('data.0.read_count', 1);
        $this->api('POST', "announcements/{$id}/withdraw", $admin)->assertOk();
        $this->api('GET', 'me/announcements', $asha)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_admin_posts_to_everyone_and_roles(): void
    {
        Notification::fake();
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $librarian = $this->member($institution, ['librarian'], ['email' => 'lib@example.edu']);
        $this->api('POST', 'employees', $admin, ['name' => 'Dr. Ravi', 'email' => 'ravi@example.edu', 'staff_type' => 'teaching', 'employment_type' => 'permanent', 'joined_on' => '2020-06-01', 'send_invite' => false])->assertCreated();

        $base = ['title' => 'Campus closed on Monday', 'body' => 'Holiday for the state festival.', 'category' => 'general', 'priority' => 'normal', 'is_pinned' => true];

        $everyone = $this->api('POST', 'announcements', $admin, [...$base, 'audience_type' => 'staff'])->assertCreated()->json('data.id');
        $this->api('POST', "announcements/{$everyone}/publish", $admin)->assertOk()->assertJsonPath('data.recipients', 1);
        $this->api('POST', "announcements/{$everyone}/publish", $admin)->assertUnprocessable();

        $roles = $this->api('POST', 'announcements', $admin, [...$base, 'title' => 'Library stock check', 'audience_type' => 'roles', 'audience_ids' => ['librarian']])->assertCreated()->json('data.id');
        $this->api('POST', "announcements/{$roles}/publish", $admin)->assertOk();
        $this->api('GET', 'me/announcements', $librarian)->assertOk()->assertJsonPath('data.0.title', 'Library stock check');

        $empty = $this->api('POST', 'announcements', $admin, [...$base, 'audience_type' => 'students'])->json('data.id');
        $this->api('POST', "announcements/{$empty}/publish", $admin)->assertUnprocessable()->assertJsonPath('code', 'EMPTY_AUDIENCE');
        $this->api('DELETE', "announcements/{$empty}", $admin)->assertOk();
    }
}
