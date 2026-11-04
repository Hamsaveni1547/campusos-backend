<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use App\Modules\Admissions\Models\AdmissionCycle;
use App\Modules\Admissions\Models\AdmissionSeat;
use App\Modules\Admissions\Models\Application;
use App\Modules\Admissions\Services\AdmissionService;
use App\Modules\Identity\Actions\Users\CreateUser;
use App\Modules\Identity\Models\Role;
use App\Modules\Institution\Models\AcademicYear;
use App\Modules\Institution\Models\Programme;
use Database\Seeders\Support\CampusSeeder;

/**
 * An open 2026-27 admission round (lateral entry and PG spot round) with seats per programme and quota,
 * and applications at every stage: draft, submitted, correction requested, eligible, ineligible,
 * approved, offered, offer accepted, admitted, rejected and withdrawn. Applicant sign-in:
 * applicant@bit.campusos.test.
 */
class AdmissionsSeeder extends CampusSeeder
{
    protected function seed(): void
    {
        if (AdmissionCycle::query()->exists()) {
            return;
        }

        $cycle = AdmissionCycle::query()->create([
            'academic_year_id' => AcademicYear::query()->where('name', '2026-27')->value('id'),
            'name' => 'Admissions 2026-27 — Spot round',
            'status' => 'open',
            'opens_on' => now()->subDays(20)->toDateString(),
            'closes_on' => now()->addDays(30)->toDateString(),
            'application_fee' => 750,
            'offer_valid_days' => 7,
            'required_documents' => [],
            'instructions' => 'Lateral entry (diploma holders) to BE 3rd semester and remaining PG seats. Bring original marks cards for verification.',
        ]);

        $seats = [];
        foreach ([['BE-CS', 'lateral', 12, 60], ['BE-AIDS', 'lateral', 6, 60], ['BE-IT', 'lateral', 6, 60], ['MCA', 'cet', 10, 50], ['MCA', 'management', 6, 50], ['MBA', 'cet', 10, 50], ['MBA', 'management', 6, 50], ['MTECH-CSE', 'cet', 4, 55]] as [$programme, $quota, $total, $minimum]) {
            $seats[$programme.'-'.$quota] = AdmissionSeat::query()->create([
                'admission_cycle_id' => $cycle->id, 'programme_id' => Programme::query()->where('code', $programme)->value('id'),
                'quota' => $quota, 'total' => $total, 'min_percentage' => $minimum, 'admission_fee' => $quota === 'management' ? 185000 : 65000,
            ]);
        }

        $service = app(AdmissionService::class);
        $office = $this->user('admissions@'.self::DOMAIN);
        $principal = $this->user('principal@'.self::DOMAIN);

        // [name, email, seat, percentage, final stage]
        foreach ([
            ['Akash Verma', 'applicant@'.self::DOMAIN, 'BE-CS-lateral', 78.4, 'submitted'],
            ['Bindu Krishnan', null, 'BE-CS-lateral', 81.2, 'draft'],
            ['Chetan Pai', null, 'BE-AIDS-lateral', 69.5, 'correction_requested'],
            ['Darshini Naik', null, 'MCA-cet', 74.0, 'eligible'],
            ['Eshwar Rao', null, 'MCA-cet', 42.5, 'ineligible'],
            ['Fathima Sheikh', null, 'MBA-management', 66.0, 'approved'],
            ['Ganesh Hegde', null, 'MBA-cet', 71.3, 'offered'],
            ['Hema Latha', null, 'MCA-management', 63.8, 'offer_accepted'],
            ['Irfan Ali', null, 'BE-IT-lateral', 85.6, 'admitted'],
            ['Janani Subramanian', null, 'MTECH-CSE-cet', 72.9, 'admitted'],
            ['Kishore Gowda', null, 'BE-CS-lateral', 61.0, 'rejected'],
            ['Latha Prakash', null, 'MBA-cet', 77.7, 'withdrawn'],
        ] as $n => [$name, $email, $seat, $percentage, $stage]) {
            $applicant = $this->applicant($name, $email ?? $this->email($name));
            $application = $this->as($applicant, fn () => $service->start($applicant, $seats[$seat], [
                'category' => ['GM', '2A', '3B', 'SC', 'EWS'][$n % 5],
                'personal' => ['full_name' => $name, 'date_of_birth' => (2000 + $n % 6).'-0'.(1 + $n % 9).'-1'.($n % 9), 'gender' => $n % 2 ? 'female' : 'male', 'address' => 'Bengaluru, Karnataka'],
                'guardian' => ['name' => 'Parent of '.$name, 'phone' => '+91944800'.str_pad((string) $n, 4, '0', STR_PAD_LEFT)],
                'academic' => ['percentage' => $percentage, 'board' => str_contains($seat, 'lateral') ? 'DTE Karnataka (Diploma)' : 'Bangalore University', 'year_of_passing' => 2026],
            ]));

            $this->advance($service, $application, $applicant, $stage, $office, $principal);
        }
    }

    private function advance(AdmissionService $service, Application $application, User $applicant, string $stage, User $office, User $principal): void
    {
        if ($stage === 'draft') {
            return;
        }

        $this->as($applicant, fn () => $service->submit($application, $this->fresh($applicant)));

        if ($stage === 'submitted') {
            return;
        }

        if ($stage === 'withdrawn') {
            $this->as($applicant, fn () => $service->withdraw($application->refresh(), $this->fresh($applicant), 'Got admission closer to home.'));

            return;
        }

        $this->as($office, function () use ($service, $application, $applicant, $stage, $office, $principal): void {
            if ($stage === 'correction_requested') {
                $service->requestCorrection($application->refresh(), 'Diploma final-semester marks card is unclear; upload a clearer copy.', $this->fresh($office));

                return;
            }

            if ($stage === 'rejected') {
                $service->reject($application->refresh(), 'Diploma is not in a related branch.', $this->fresh($office));

                return;
            }

            $service->completeVerification($application->refresh(), $this->fresh($office));

            if (in_array($stage, ['eligible', 'ineligible'], true)) {
                return;
            }

            if ($application->refresh()->seat->quota->needsApproval()) {
                $this->as($principal, fn () => $service->approve($application->refresh(), $this->fresh($principal), 'Approved by the admission committee.'));
            }

            if ($stage === 'approved') {
                return;
            }

            $service->offer($application->refresh(), $this->fresh($office));

            if ($stage === 'offered') {
                return;
            }

            $this->as($applicant, fn () => $service->acceptOffer($application->refresh(), $this->fresh($applicant)));

            if ($stage === 'admitted') {
                $service->confirm($application->refresh(), $this->fresh($office), (float) $application->seat->admission_fee, 'BIT-FEE-'.(26000 + $application->id));
                $this->activate($application->refresh()->user);
            }
        });
    }

    private function applicant(string $name, string $email): User
    {
        $role = Role::query()->whereNull('institution_id')->where('name', 'applicant')->firstOrFail();
        $user = app(CreateUser::class)->handle(['name' => $name, 'email' => $email], null, [$role], sendInvite: false)['user'];

        return $this->activate($user);
    }
}
