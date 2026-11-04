<?php

namespace Tests\Feature\Shared;

use App\Models\User;
use App\Modules\Library\Models\LibraryBook;
use App\Modules\Library\Models\LibraryCopy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EnhancementsE2Test extends TestCase
{
    use RefreshDatabase;

    public function test_library_books_import_from_csv(): void
    {
        $institution = $this->institution();
        $librarian = $this->member($institution, ['librarian']);

        $this->api('GET', 'imports/templates/library_books', $librarian)->assertOk();

        $bad = "title,authors,isbn,accession_nos\nDBMS,Korth,9780073523323,A1;A1\n";
        $badFile = $this->api('POST', 'files', $librarian, ['file' => UploadedFile::fake()->createWithContent('bad.csv', $bad), 'purpose' => 'import'])->json('data.id');
        $badImport = $this->api('POST', 'imports', $librarian, ['type' => 'library_books', 'file_id' => $badFile])->assertStatus(202)->json('data.id');
        $this->assertSame('invalid', $this->api('GET', "imports/{$badImport}", $librarian)->json('data.status'));

        $csv = "title,authors,isbn,publisher,published_year,subject,accession_nos,shelf\n"
            ."Database System Concepts,Silberschatz; Korth,9780073523323,McGraw Hill,2019,Databases,ACC001;ACC002,R1\n"
            ."Operating System Concepts,Silberschatz,9781118063330,Wiley,2018,Operating systems,ACC003,R2\n"
            ."Database System Concepts,Silberschatz; Korth,9780073523323,,,,ACC004,R1\n";
        $fileId = $this->api('POST', 'files', $librarian, ['file' => UploadedFile::fake()->createWithContent('books.csv', $csv), 'purpose' => 'import'])->json('data.id');
        $import = $this->api('POST', 'imports', $librarian, ['type' => 'library_books', 'file_id' => $fileId])->assertStatus(202)->json('data.id');
        $this->assertSame('validated', $this->api('GET', "imports/{$import}", $librarian)->json('data.status'));
        $this->api('POST', "imports/{$import}/commit", $librarian)->assertStatus(202);

        $this->assertSame(2, LibraryBook::query()->withoutGlobalScopes()->count());
        $this->assertSame(4, LibraryCopy::query()->withoutGlobalScopes()->where('status', 'available')->count());
    }

    public function test_attachments_on_complaints_and_announcements(): void
    {
        Notification::fake();
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $staff = $this->member($institution, ['staff'], ['email' => 'staff@example.edu']);
        $other = $this->member($institution, ['staff'], ['email' => 'other@example.edu']);
        $category = $this->api('POST', 'complaint-categories', $admin, ['name' => 'Infrastructure', 'sla_hours' => 48, 'is_restricted' => false])->json('data.id');

        $photo = $this->api('POST', 'files', $staff, ['file' => UploadedFile::fake()->image('leak.png'), 'purpose' => 'attachment'])->assertCreated()->json('data.id');
        $foreign = $this->api('POST', 'files', $other, ['file' => UploadedFile::fake()->image('x.png'), 'purpose' => 'attachment'])->json('data.id');

        $this->api('POST', 'me/complaints', $staff, ['category_id' => $category, 'subject' => 'Water leak', 'description' => 'Lab 2 ceiling leaks.', 'attachment_ids' => [$foreign]])->assertUnprocessable()->assertJsonPath('code', 'FILE_INVALID');
        $complaint = $this->api('POST', 'me/complaints', $staff, ['category_id' => $category, 'subject' => 'Water leak', 'description' => 'Lab 2 ceiling leaks.', 'attachment_ids' => [$photo]])->assertCreated()->json('data.id');

        $this->api('GET', "complaints/{$complaint}", $admin)->assertOk()->assertJsonPath('data.attachments.0.name', 'leak.png');
        $this->api('GET', "files/{$photo}/download-url", $admin)->assertOk();
        $this->api('GET', "files/{$photo}/download-url", $other)->assertNotFound();

        $circular = $this->api('POST', 'files', $admin, ['file' => UploadedFile::fake()->create('circular.pdf', 50, 'application/pdf'), 'purpose' => 'attachment'])->json('data.id');
        $id = $this->api('POST', 'announcements', $admin, ['title' => 'Exam rules', 'body' => 'See the attached circular.', 'category' => 'general', 'priority' => 'normal', 'audience_type' => 'roles', 'audience_ids' => ['staff'], 'attachment_ids' => [$circular]])->assertCreated()->json('data.id');
        $this->api('GET', "files/{$circular}/download-url", $other)->assertNotFound();
        $this->api('POST', "announcements/{$id}/publish", $admin)->assertOk();
        $this->api('GET', "announcements/{$id}", $other)->assertOk()->assertJsonPath('data.attachments.0.name', 'circular.pdf');
        $this->api('GET', "files/{$circular}/download-url", $other)->assertOk();
    }

    public function test_timetable_calendar_feed_is_private(): void
    {
        $institution = $this->institution();
        $staff = $this->member($institution, ['staff']);

        $url = $this->api('GET', 'me/timetable/calendar-link', $staff)->assertOk()->json('data.url');
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->assertSee('BEGIN:VCALENDAR', false);
        $this->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();
    }

    private function employee(User $admin, string $email): void
    {
        $this->api('POST', 'employees', $admin, ['name' => 'Other', 'email' => $email, 'staff_type' => 'non_teaching', 'employment_type' => 'permanent', 'joined_on' => '2020-06-01', 'send_invite' => false]);
    }
}
