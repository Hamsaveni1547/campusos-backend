<?php

namespace Tests\Feature\Library;

use App\Models\User;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Library\Models\LibraryLoan;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalogue_issue_limits_renew_return_with_fine_and_dues(): void
    {
        $this->travelTo('2026-11-02 10:00');
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $librarian = $this->member($institution, ['librarian'], ['email' => 'library@example.edu']);
        $this->setting($institution, 'library', ['student_limit' => 2, 'student_days' => 14, 'fine_per_day' => 2, 'max_renewals' => 1]);

        $department = $this->api('POST', 'departments', $admin, ['code' => 'MCA', 'name' => 'MCA'])->json('data');
        $programme = $this->api('POST', 'programmes', $admin, ['department_id' => $department['id'], 'code' => 'MCA', 'name' => 'MCA', 'level' => 'pg', 'duration_years' => 2])->json('data');
        $register = $this->api('POST', 'students', $admin, ['name' => 'Asha', 'email' => 'asha@example.edu', 'programme_id' => $programme['id'], 'admission_type' => 'cet', 'admitted_on' => '2026-08-01', 'send_invite' => false])->json('data.register_no');
        $asha = User::query()->where('email', 'asha@example.edu')->firstOrFail();
        $asha->forceFill(['password' => Hash::make(UserFactory::PASSWORD), 'status' => UserStatus::Active, 'email_verified_at' => now(), 'must_change_password' => false])->save();

        $book = $this->api('POST', 'library/books', $librarian, ['title' => 'Database System Concepts', 'authors' => 'Silberschatz, Korth, Sudarshan', 'isbn' => '9780073523323', 'subject' => 'Databases', 'copies' => [['accession_no' => 'BIT0001'], ['accession_no' => 'BIT0002'], ['accession_no' => 'BIT0003']]])->assertCreated()->json('data.id');
        $this->api('POST', 'library/books/'.$book.'/copies', $librarian, ['accession_no' => 'BIT0001'])->assertStatus(422);
        $this->api('POST', 'library/books', $asha, ['title' => 'x', 'authors' => 'y'])->assertForbidden();
        $this->api('GET', 'library/books?filter[search]=Korth', $asha)->assertOk()->assertJsonPath('data.0.available', 3);

        $this->api('POST', 'library/issue', $librarian, ['accession_no' => 'BIT0001', 'member' => 'nobody@example.edu'])->assertNotFound()->assertJsonPath('code', 'MEMBER_NOT_FOUND');
        $first = $this->api('POST', 'library/issue', $librarian, ['accession_no' => 'BIT0001', 'member' => $register])->assertCreated()->assertJsonPath('data.due_on', '2026-11-16')->json('data.id');
        $this->api('POST', 'library/issue', $librarian, ['accession_no' => 'BIT0001', 'member' => $register])->assertStatus(409)->assertJsonPath('code', 'COPY_UNAVAILABLE');
        $this->api('POST', 'library/issue', $librarian, ['accession_no' => 'BIT0002', 'member' => 'asha@example.edu'])->assertCreated();
        $this->api('POST', 'library/issue', $librarian, ['accession_no' => 'BIT0003', 'member' => $register])->assertStatus(409)->assertJsonPath('code', 'LIMIT_REACHED');

        // The student renews once online; the second renewal is refused.
        $this->api('POST', 'library/loans/'.$first.'/renew', $asha)->assertOk()->assertJsonPath('data.renewals', 1);
        $this->api('POST', 'library/loans/'.$first.'/renew', $asha)->assertStatus(409)->assertJsonPath('code', 'RENEWAL_LIMIT');

        // BIT0002 was due 5 days ago: returning it now costs ₹10, which blocks new loans until paid.
        LibraryLoan::query()->whereHas('copy', fn ($q) => $q->where('accession_no', 'BIT0002'))->update(['due_on' => '2026-10-28']);
        $this->api('GET', 'library/loans?filter[state]=overdue', $librarian)->assertOk()->assertJsonCount(1, 'data');
        $this->api('POST', 'library/return', $librarian, ['accession_no' => 'BIT0002'])->assertOk()->assertJsonPath('data.fine', 10)->assertJsonPath('data.fine_status', 'due');
        $this->api('POST', 'library/issue', $librarian, ['accession_no' => 'BIT0003', 'member' => $register])->assertStatus(409)->assertJsonPath('code', 'FINE_PENDING');
        $this->api('GET', 'me/library', $asha)->assertOk()->assertJsonPath('data.dues.fines', 10)->assertJsonPath('data.dues.no_dues', false)->assertJsonCount(1, 'data.current');

        $loan = $this->api('GET', 'library/loans?filter[state]=fines', $librarian)->json('data.0.id');
        $this->api('POST', 'library/loans/'.$loan.'/fine', $librarian, ['how' => 'waived'])->assertStatus(422);
        $this->api('POST', 'library/loans/'.$loan.'/fine', $librarian, ['how' => 'paid'])->assertOk();
        $this->api('POST', 'library/return', $librarian, ['accession_no' => 'BIT0001'])->assertOk()->assertJsonPath('data.fine', 0);
        $this->api('GET', 'users/'.$asha->ulid.'/library-dues', $librarian)->assertOk()->assertJsonPath('data.no_dues', true);
        $this->api('GET', 'library/summary', $librarian)->assertJsonPath('data.copies', 3)->assertJsonPath('data.on_loan', 0);
    }
}
