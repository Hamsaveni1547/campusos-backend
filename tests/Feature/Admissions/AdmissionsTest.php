<?php

namespace Tests\Feature\Admissions;

use App\Models\User;
use App\Modules\Admissions\Models\AdmissionSeat;
use App\Modules\Admissions\Models\Application;
use App\Modules\Admissions\Notifications\ApplicationStatusNotification;
use App\Modules\Institution\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdmissionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An open cycle with two seat rows (CET: 2 seats, min 60%; Management: 1 seat).
     *
     * @return array{institution: Institution, admin: User, cycle: array<string, mixed>, cet: string, management: string}
     */
    private function openCycle(): array
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);

        $department = $this->api('POST', 'departments', $admin, ['code' => 'CSE', 'name' => 'Computer Science'])->json('data');
        $programme = $this->api('POST', 'programmes', $admin, ['department_id' => $department['id'], 'code' => 'BE-CSE', 'name' => 'B.E. Computer Science', 'level' => 'ug', 'duration_years' => 4])->json('data');
        $year = $this->api('POST', 'academic-years', $admin, ['name' => '2026-27', 'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonths(11)->toDateString()])->json('data');

        $cycle = $this->api('POST', 'admission-cycles', $admin, [
            'academic_year_id' => $year['id'], 'name' => 'B.E. 2026', 'opens_on' => now()->subDay()->toDateString(), 'closes_on' => now()->addMonth()->toDateString(),
            'offer_valid_days' => 5, 'required_documents' => ['marks_card'],
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data');

        $this->api('POST', 'admission-cycles/'.$cycle['id'].'/open', $admin)->assertStatus(422)->assertJsonPath('code', 'NO_SEATS');

        $cet = $this->api('POST', 'admission-cycles/'.$cycle['id'].'/seats', $admin, ['programme_id' => $programme['id'], 'quota' => 'cet', 'total' => 2, 'min_percentage' => 60, 'admission_fee' => 85000])
            ->assertCreated()->assertJsonPath('data.available', 2)->json('data.id');
        $management = $this->api('POST', 'admission-cycles/'.$cycle['id'].'/seats', $admin, ['programme_id' => $programme['id'], 'quota' => 'management', 'total' => 1])
            ->assertCreated()->assertJsonPath('data.needs_approval', true)->json('data.id');
        $this->api('POST', 'admission-cycles/'.$cycle['id'].'/seats', $admin, ['programme_id' => $programme['id'], 'quota' => 'cet', 'total' => 5])
            ->assertStatus(409)->assertJsonPath('code', 'SEAT_ROW_EXISTS');

        $this->api('POST', 'admission-cycles/'.$cycle['id'].'/open', $admin)->assertOk()->assertJsonPath('data.is_accepting', true);

        return ['institution' => $institution, 'admin' => $admin, 'cycle' => $cycle, 'cet' => $cet, 'management' => $management];
    }

    /**
     * Applicant fills the form, uploads the marks card and submits.
     */
    private function submittedApplication(User $applicant, string $seat, float $percentage = 82.5): string
    {
        $id = $this->api('POST', 'me/applications', $applicant, [
            'seat_id' => $seat,
            'category' => 'GM',
            'personal' => ['full_name' => $applicant->name, 'date_of_birth' => '2008-05-14', 'gender' => 'female'],
            'academic' => ['board' => 'Karnataka PUC', 'percentage' => $percentage, 'year_of_passing' => 2026],
            'entrance' => ['exam' => 'KCET', 'rank' => 4512],
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

        $this->api('POST', 'me/applications/'.$id.'/submit', $applicant)->assertStatus(422)->assertJsonPath('code', 'DOCUMENTS_MISSING');

        $fileId = $this->api('POST', 'files', $applicant, ['file' => UploadedFile::fake()->create('marks.pdf', 80, 'application/pdf'), 'purpose' => 'document'])->assertCreated()->json('data.id');
        $this->api('POST', 'me/applications/'.$id.'/documents', $applicant, ['document_type' => 'marks_card', 'file_id' => $fileId])->assertOk();

        $this->api('POST', 'me/applications/'.$id.'/submit', $applicant)->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.can_edit', false);

        return $id;
    }

    private function verifyAll(User $staff, string $application): void
    {
        $document = $this->api('GET', 'applications/'.$application, $staff)->json('data.documents.0.id');
        $this->api('POST', 'applications/'.$application.'/documents/'.$document.'/review', $staff, ['verified' => true])->assertOk();
    }

    public function test_full_cet_admission_from_application_to_student(): void
    {
        Notification::fake();
        ['institution' => $institution, 'admin' => $admin, 'cet' => $cet] = $this->openCycle();
        $applicant = $this->member($institution, ['applicant']);

        $this->api('GET', 'admissions/cycles/open', $applicant)->assertOk()->assertJsonPath('data.0.seats.0.quota', 'cet');
        $this->api('GET', 'admissions/options', $applicant)->assertOk()->assertJsonPath('data.quotas.0.label', 'CET / Government')->assertJsonPath('data.categories.0.value', 'GM');

        $id = $this->submittedApplication($applicant, $cet);
        $this->api('PUT', 'me/applications/'.$id, $applicant, ['category' => 'SC'])->assertStatus(409)->assertJsonPath('code', 'APPLICATION_LOCKED');

        $this->api('POST', 'applications/'.$id.'/complete-verification', $admin)->assertStatus(422)->assertJsonPath('code', 'DOCUMENTS_NOT_VERIFIED');
        $this->verifyAll($admin, $id);

        $this->api('POST', 'applications/'.$id.'/complete-verification', $admin)->assertOk()->assertJsonPath('data.status', 'eligible');
        $this->api('POST', 'applications/'.$id.'/offer', $admin)->assertOk()
            ->assertJsonPath('data.status', 'offered')
            ->assertJsonPath('data.seat.filled', 1);

        $this->api('POST', 'applications/'.$id.'/confirm', $admin, ['fee_amount' => 85000, 'fee_reference' => 'RCPT-1'])->assertStatus(409);
        $this->api('POST', 'me/applications/'.$id.'/accept-offer', $applicant)->assertOk()->assertJsonPath('data.status', 'offer_accepted');

        $admitted = $this->api('POST', 'applications/'.$id.'/confirm', $admin, ['fee_amount' => 85000, 'fee_reference' => 'RCPT-1'])->assertOk()
            ->assertJsonPath('data.status', 'admitted')
            ->assertJsonPath('data.fee_reference', 'RCPT-1')
            ->json('data');

        $this->assertStringStartsWith($institution->code.now()->format('y').'BECSE', $admitted['register_no']);
        $this->assertSame(['draft', 'submitted', 'verified', 'eligible', 'offered', 'offer_accepted', 'admitted'], array_column($admitted['history'], 'to'));
        $this->assertTrue($applicant->fresh()->roleAssignments()->whereHas('role', fn ($query) => $query->where('name', 'student'))->exists());

        Notification::assertSentTo($applicant, ApplicationStatusNotification::class);
    }

    public function test_seats_never_go_over_the_matrix_and_withdrawal_frees_a_seat(): void
    {
        ['institution' => $institution, 'admin' => $admin, 'cet' => $cet] = $this->openCycle();
        $ids = [];

        foreach (range(1, 3) as $n) {
            $applicant = $this->member($institution, ['applicant'], ['email' => "applicant{$n}@example.edu"]);
            $id = $this->submittedApplication($applicant, $cet);
            $this->verifyAll($admin, $id);
            $this->api('POST', 'applications/'.$id.'/complete-verification', $admin)->assertOk();
            $ids[] = [$applicant, $id];
        }

        $this->api('POST', 'applications/'.$ids[0][1].'/offer', $admin)->assertOk();
        $this->api('POST', 'applications/'.$ids[1][1].'/offer', $admin)->assertOk();
        $this->api('POST', 'applications/'.$ids[2][1].'/offer', $admin)->assertStatus(409)->assertJsonPath('code', 'NO_SEATS_LEFT');

        $this->api('POST', 'me/applications/'.$ids[0][1].'/withdraw', $ids[0][0], ['reason' => 'Joined elsewhere'])->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->assertSame(1, AdmissionSeat::query()->where('ulid', $cet)->value('filled'));

        $this->api('POST', 'applications/'.$ids[2][1].'/offer', $admin)->assertOk();
    }

    public function test_low_marks_are_ineligible_and_corrections_reopen_the_form(): void
    {
        ['institution' => $institution, 'admin' => $admin, 'cet' => $cet] = $this->openCycle();
        $applicant = $this->member($institution, ['applicant']);
        $id = $this->submittedApplication($applicant, $cet, 55);

        $document = $this->api('GET', 'applications/'.$id, $admin)->json('data.documents.0.id');
        $this->api('POST', 'applications/'.$id.'/documents/'.$document.'/review', $admin, ['verified' => false])->assertStatus(422)->assertJsonPath('code', 'REMARKS_REQUIRED');
        $this->api('POST', 'applications/'.$id.'/documents/'.$document.'/review', $admin, ['verified' => false, 'remarks' => 'Blurred scan'])->assertOk()->assertJsonPath('data.documents.0.status', 'rejected');

        $this->api('POST', 'applications/'.$id.'/request-correction', $admin, ['remarks' => 'Upload a clear marks card'])->assertOk()->assertJsonPath('data.status', 'correction_requested');

        $fileId = $this->api('POST', 'files', $applicant, ['file' => UploadedFile::fake()->create('marks2.pdf', 60, 'application/pdf'), 'purpose' => 'document'])->json('data.id');
        $this->api('POST', 'me/applications/'.$id.'/documents', $applicant, ['document_type' => 'marks_card', 'file_id' => $fileId])->assertOk()->assertJsonPath('data.documents.0.status', 'pending');
        $this->api('POST', 'me/applications/'.$id.'/submit', $applicant)->assertOk()->assertJsonPath('data.status', 'submitted');

        $this->verifyAll($admin, $id);
        $this->api('POST', 'applications/'.$id.'/complete-verification', $admin)->assertOk()
            ->assertJsonPath('data.status', 'ineligible')
            ->assertJsonPath('data.remarks', 'Qualifying percentage 55.00 is below the minimum of 60.00.');
    }

    public function test_management_quota_needs_the_heads_approval(): void
    {
        ['institution' => $institution, 'admin' => $admin, 'management' => $management] = $this->openCycle();
        $head = $this->member($institution, ['institution_head']);
        $staff = $this->member($institution, ['admin_staff']);
        $applicant = $this->member($institution, ['applicant']);

        $id = $this->submittedApplication($applicant, $management, 50);
        $this->verifyAll($staff, $id);
        $this->api('POST', 'applications/'.$id.'/complete-verification', $staff)->assertOk()->assertJsonPath('data.status', 'eligible');

        $this->api('POST', 'applications/'.$id.'/offer', $staff)->assertStatus(409)->assertJsonPath('code', 'APPROVAL_REQUIRED');
        $this->api('POST', 'applications/'.$id.'/approve', $staff)->assertForbidden();
        $this->api('POST', 'applications/'.$id.'/approve', $head, ['remarks' => 'Approved by principal'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->api('POST', 'applications/'.$id.'/offer', $staff)->assertOk()->assertJsonPath('data.status', 'offered');
    }

    public function test_unaccepted_offers_expire_and_release_the_seat(): void
    {
        ['institution' => $institution, 'admin' => $admin, 'cet' => $cet] = $this->openCycle();
        $applicant = $this->member($institution, ['applicant']);
        $id = $this->submittedApplication($applicant, $cet);
        $this->verifyAll($admin, $id);
        $this->api('POST', 'applications/'.$id.'/complete-verification', $admin);
        $this->api('POST', 'applications/'.$id.'/offer', $admin)->assertOk();

        $this->travel(6)->days();
        $this->artisan('admissions:expire-offers')->assertSuccessful();

        $this->assertSame('expired', Application::query()->where('ulid', $id)->value('status')->value);
        $this->assertSame(0, AdmissionSeat::query()->where('ulid', $cet)->value('filled'));

        $this->travelBack();
        $this->api('POST', 'me/applications/'.$id.'/accept-offer', $applicant)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATUS');
    }

    public function test_access_rules(): void
    {
        ['institution' => $institution, 'admin' => $admin, 'cycle' => $cycle, 'cet' => $cet] = $this->openCycle();
        $applicant = $this->member($institution, ['applicant']);
        $other = $this->member($institution, ['applicant'], ['email' => 'other@example.edu']);
        $faculty = $this->member($institution, ['faculty']);

        $id = $this->submittedApplication($applicant, $cet);

        $this->api('POST', 'me/applications', $applicant, ['seat_id' => $cet])->assertStatus(409)->assertJsonPath('code', 'APPLICATION_EXISTS');
        $this->api('GET', 'me/applications/'.$id, $other)->assertNotFound();
        $this->api('GET', 'applications', $applicant)->assertForbidden();
        $this->api('POST', 'admission-cycles', $faculty, ['name' => 'X'])->assertForbidden();
        $this->api('GET', 'applications', $admin)->assertOk()->assertJsonPath('data.0.id', $id);
        $this->api('GET', 'applications/summary?filter[cycle]='.$cycle['id'], $admin)->assertOk()->assertJsonPath('data.submitted', 1);

        $outsider = $this->member($this->institution(['code' => 'BMS']), ['institution_admin'], ['email' => 'admin@bms.edu']);
        $this->api('GET', 'applications/'.$id, $outsider)->assertNotFound();

        $this->api('DELETE', 'admission-cycles/'.$cycle['id'], $admin)->assertStatus(409)->assertJsonPath('code', 'IN_USE');
        $this->api('POST', 'admission-cycles/'.$cycle['id'].'/close', $admin)->assertOk();
        $this->api('POST', 'me/applications', $other, ['seat_id' => $cet])->assertStatus(409)->assertJsonPath('code', 'CYCLE_CLOSED');
    }
}
