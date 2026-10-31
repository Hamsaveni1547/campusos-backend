<?php

namespace App\Modules\Admissions\Services;

use App\Models\User;
use App\Modules\Admissions\Enums\ApplicationStatus;
use App\Modules\Admissions\Enums\DocumentStatus;
use App\Modules\Admissions\Models\AdmissionSeat;
use App\Modules\Admissions\Models\Application;
use App\Modules\Admissions\Models\ApplicationDocument;
use App\Modules\Admissions\Notifications\ApplicationStatusNotification;
use App\Modules\Students\Services\StudentService;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Files\StoredFile;
use App\Shared\Numbering\NumberGenerator;
use App\Shared\Settings\SettingsRepository;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Support\Facades\DB;

/**
 * The whole application lifecycle: draft, submit, verify, eligibility, approval,
 * seat offer, acceptance, confirmation (admitted), withdrawal and offer expiry.
 */
class AdmissionService
{
    public function __construct(
        private CurrentInstitution $currentInstitution,
        private NumberGenerator $numbers,
        private SettingsRepository $settings,
        private StudentService $students,
    ) {}

    // ----- applicant ------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data  personal, guardian, academic, entrance, category
     */
    public function start(User $applicant, AdmissionSeat $seat, array $data): Application
    {
        $cycle = $seat->cycle;

        if (! $cycle->isAcceptingApplications()) {
            throw new BusinessRuleException('This admission cycle is not accepting applications.', 'CYCLE_CLOSED', 409);
        }

        $active = Application::query()
            ->where('admission_cycle_id', $cycle->id)
            ->where('user_id', $applicant->id)
            ->whereNotIn('status', [ApplicationStatus::Withdrawn, ApplicationStatus::Rejected, ApplicationStatus::Ineligible, ApplicationStatus::Expired])
            ->exists();

        if ($active) {
            throw new BusinessRuleException('You already have an application in this admission cycle.', 'APPLICATION_EXISTS', 409);
        }

        $application = Application::query()->create(array_merge($this->formData($data), [
            'admission_cycle_id' => $cycle->id,
            'admission_seat_id' => $seat->id,
            'user_id' => $applicant->id,
        ]));

        $this->record($application, null, ApplicationStatus::Draft, null, $applicant);

        return $application;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateForm(Application $application, array $data, ?AdmissionSeat $seat = null): Application
    {
        $this->assertEditable($application);

        if ($seat !== null) {
            if ($seat->admission_cycle_id !== $application->admission_cycle_id) {
                throw new BusinessRuleException('Choose a programme from the same admission cycle.', 'SEAT_NOT_IN_CYCLE', 422);
            }

            $application->admission_seat_id = $seat->id;
        }

        $application->fill($this->formData($data))->save();

        return $application;
    }

    public function attachDocument(Application $application, string $type, StoredFile $file): ApplicationDocument
    {
        $this->assertEditable($application);

        return ApplicationDocument::query()->updateOrCreate(
            ['application_id' => $application->id, 'document_type' => $type],
            ['file_id' => $file->id, 'status' => DocumentStatus::Pending, 'remarks' => null, 'verified_by' => null, 'verified_at' => null],
        );
    }

    public function submit(Application $application, User $applicant): Application
    {
        $this->assertEditable($application);

        if (! $application->cycle->isAcceptingApplications()) {
            throw new BusinessRuleException('This admission cycle is no longer accepting applications.', 'CYCLE_CLOSED', 409);
        }

        foreach (['personal', 'academic'] as $section) {
            if (empty($application->{$section})) {
                throw new BusinessRuleException('Complete the '.$section.' details before submitting.', 'APPLICATION_INCOMPLETE', 422, ['section' => $section]);
            }
        }

        if ($application->qualifying_percentage === null) {
            throw new BusinessRuleException('Enter your qualifying examination percentage before submitting.', 'APPLICATION_INCOMPLETE', 422, ['section' => 'academic']);
        }

        $missing = $this->missingDocuments($application);

        if ($missing !== []) {
            throw new BusinessRuleException('Upload these documents first: '.implode(', ', $missing).'.', 'DOCUMENTS_MISSING', 422, ['missing' => $missing]);
        }

        if ($application->application_no === null) {
            $institution = $this->currentInstitution->require();
            $application->application_no = $this->numbers->next(
                'application_no',
                (string) $this->settings->get('admissions', 'application_no_format'),
                $institution->id,
                tokens: ['INST' => $institution->code],
            );
        }

        $application->submitted_at = now();
        $application->remarks = null;

        return $this->move($application, ApplicationStatus::Submitted, $applicant);
    }

    public function acceptOffer(Application $application, User $applicant): Application
    {
        if ($application->status === ApplicationStatus::Offered && $application->offer_expires_at?->isPast()) {
            $this->expire($application);

            throw new BusinessRuleException('This offer has expired.', 'OFFER_EXPIRED', 409);
        }

        return $this->move($application, ApplicationStatus::OfferAccepted, $applicant);
    }

    public function withdraw(Application $application, User $actor, ?string $reason = null): Application
    {
        return DB::transaction(function () use ($application, $actor, $reason): Application {
            $this->releaseSeat($application);

            return $this->move($application, ApplicationStatus::Withdrawn, $actor, $reason);
        });
    }

    // ----- staff -----------------------------------------------------------

    public function reviewDocument(ApplicationDocument $document, bool $verified, ?string $remarks, User $staff): ApplicationDocument
    {
        $application = $document->application;

        if (! in_array($application->status, [ApplicationStatus::Submitted, ApplicationStatus::Verified], true)) {
            throw new BusinessRuleException('Documents can be checked only on a submitted application.', 'INVALID_STATUS', 409);
        }

        if (! $verified && blank($remarks)) {
            throw new BusinessRuleException('Say why the document is rejected.', 'REMARKS_REQUIRED', 422);
        }

        $document->update([
            'status' => $verified ? DocumentStatus::Verified : DocumentStatus::Rejected,
            'remarks' => $remarks,
            'verified_by' => $staff->id,
            'verified_at' => now(),
        ]);

        return $document;
    }

    public function requestCorrection(Application $application, string $remarks, User $staff): Application
    {
        return $this->move($application, ApplicationStatus::CorrectionRequested, $staff, $remarks);
    }

    /**
     * All documents verified → verified, then the eligibility rule decides eligible or ineligible.
     */
    public function completeVerification(Application $application, User $staff): Application
    {
        $pending = $application->documents()->where('status', '!=', DocumentStatus::Verified->value)->pluck('document_type')->all();
        $missing = $this->missingDocuments($application);

        if ($pending !== [] || $missing !== []) {
            throw new BusinessRuleException('Verify every document first.', 'DOCUMENTS_NOT_VERIFIED', 422, ['pending' => array_values(array_unique([...$pending, ...$missing]))]);
        }

        return DB::transaction(function () use ($application, $staff): Application {
            $this->move($application, ApplicationStatus::Verified, $staff);

            $minimum = $application->seat->min_percentage;

            if ($minimum !== null && (float) $application->qualifying_percentage < (float) $minimum) {
                return $this->move($application, ApplicationStatus::Ineligible, $staff, 'Qualifying percentage '.$application->qualifying_percentage.' is below the minimum of '.$minimum.'.');
            }

            return $this->move($application, ApplicationStatus::Eligible, $staff);
        });
    }

    public function approve(Application $application, User $head, ?string $remarks = null): Application
    {
        if (! $application->seat->quota->needsApproval()) {
            throw new BusinessRuleException('Only management and NRI quota applications need approval.', 'APPROVAL_NOT_NEEDED', 422);
        }

        return $this->move($application, ApplicationStatus::Approved, $head, $remarks);
    }

    public function reject(Application $application, string $remarks, User $staff): Application
    {
        return $this->move($application, ApplicationStatus::Rejected, $staff, $remarks);
    }

    /**
     * Hold a seat and send the offer. The seat row is locked so seats never go over the matrix.
     */
    public function offer(Application $application, User $staff): Application
    {
        if ($application->status === ApplicationStatus::Eligible && $application->seat->quota->needsApproval()) {
            throw new BusinessRuleException('This quota needs the institution head\'s approval before an offer.', 'APPROVAL_REQUIRED', 409);
        }

        if (! $application->status->canMoveTo(ApplicationStatus::Offered)) {
            throw $this->invalidMove($application, ApplicationStatus::Offered);
        }

        return DB::transaction(function () use ($application, $staff): Application {
            /** @var AdmissionSeat $seat */
            $seat = AdmissionSeat::query()->lockForUpdate()->findOrFail($application->admission_seat_id);

            if ($seat->filled >= $seat->total) {
                throw new BusinessRuleException('No seats left in this programme and quota.', 'NO_SEATS_LEFT', 409);
            }

            $seat->increment('filled');
            $application->seat_held = true;
            $application->offered_at = now();
            $application->offer_expires_at = now()->addDays($application->cycle->offer_valid_days)->endOfDay();

            return $this->move($application, ApplicationStatus::Offered, $staff);
        });
    }

    /**
     * Admission fee received → admitted, with a register number and the Student role.
     */
    public function confirm(Application $application, User $staff, float $feeAmount, string $feeReference): Application
    {
        if (! $application->status->canMoveTo(ApplicationStatus::Admitted)) {
            throw $this->invalidMove($application, ApplicationStatus::Admitted);
        }

        return DB::transaction(function () use ($application, $staff, $feeAmount, $feeReference): Application {
            $institution = $this->currentInstitution->require();
            $programme = $application->seat->programme;

            $application->fee_amount = number_format($feeAmount, 2, '.', '');
            $application->fee_reference = $feeReference;
            $application->admitted_at = now();
            $application->register_no = $this->numbers->next(
                'register_no',
                (string) $this->settings->get('admissions', 'register_no_format'),
                $institution->id,
                scope: $programme->code.'-'.now()->format('Y'),
                tokens: ['INST' => $institution->code, 'PROG' => preg_replace('/[^A-Z0-9]/', '', strtoupper($programme->code))],
            );

            $this->move($application, ApplicationStatus::Admitted, $staff);
            $this->students->createFromApplication($application, $staff);

            return $application;
        });
    }

    /**
     * Offers past their expiry lose the seat. Returns how many expired.
     */
    public function expireDueOffers(): int
    {
        $count = 0;

        Application::query()
            ->where('status', ApplicationStatus::Offered)
            ->where('offer_expires_at', '<', now())
            ->each(function (Application $application) use (&$count): void {
                $this->expire($application);
                $count++;
            });

        return $count;
    }

    // ----- internals -------------------------------------------------------

    private function expire(Application $application): void
    {
        DB::transaction(function () use ($application): void {
            $this->releaseSeat($application);
            $this->move($application, ApplicationStatus::Expired, null, 'The offer was not accepted in time.');
        });
    }

    private function releaseSeat(Application $application): void
    {
        if (! $application->seat_held || $application->status === ApplicationStatus::Admitted) {
            return;
        }

        AdmissionSeat::query()->whereKey($application->admission_seat_id)->where('filled', '>', 0)->decrement('filled');
        $application->seat_held = false;
    }

    private function move(Application $application, ApplicationStatus $to, ?User $actor, ?string $remarks = null): Application
    {
        $from = $application->status;

        if (! $from->canMoveTo($to)) {
            throw $this->invalidMove($application, $to);
        }

        $application->status = $to;
        $application->remarks = $remarks;
        $application->save();

        $this->record($application, $from, $to, $remarks, $actor);

        if ($to !== ApplicationStatus::Draft && $application->user_id !== $actor?->id) {
            $application->user->notify(ApplicationStatusNotification::fromApplication($application->loadMissing('seat.programme')));
        }

        return $application;
    }

    private function record(Application $application, ?ApplicationStatus $from, ApplicationStatus $to, ?string $remarks, ?User $actor): void
    {
        $application->history()->create([
            'institution_id' => $application->institution_id,
            'from_status' => $from,
            'to_status' => $to,
            'remarks' => $remarks,
            'changed_by' => $actor?->id,
        ]);
    }

    private function invalidMove(Application $application, ApplicationStatus $to): BusinessRuleException
    {
        return new BusinessRuleException(
            'An application that is "'.$application->status->label().'" cannot become "'.$to->label().'".',
            'INVALID_STATUS',
            409,
            ['status' => $application->status->value],
        );
    }

    private function assertEditable(Application $application): void
    {
        if (! $application->status->isEditableByApplicant()) {
            throw new BusinessRuleException('This application can no longer be changed.', 'APPLICATION_LOCKED', 409);
        }
    }

    /**
     * @return list<string>
     */
    private function missingDocuments(Application $application): array
    {
        $uploaded = $application->documents()->pluck('document_type')->all();

        return array_values(array_diff($application->cycle->requiredDocuments(), $uploaded));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function formData(array $data): array
    {
        $form = array_intersect_key($data, array_flip(['category', 'personal', 'guardian', 'academic', 'entrance']));

        if (isset($data['academic']['percentage'])) {
            $form['qualifying_percentage'] = round((float) $data['academic']['percentage'], 2);
        }

        return $form;
    }
}
