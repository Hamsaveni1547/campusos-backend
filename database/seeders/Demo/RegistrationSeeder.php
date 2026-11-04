<?php

namespace Database\Seeders\Demo;

use App\Modules\Academics\Models\TermRegistration;
use App\Modules\Academics\Services\RegistrationService;
use App\Modules\Institution\Models\Term;
use App\Modules\Students\Models\Student;
use App\Shared\Exceptions\BusinessRuleException;
use Database\Seeders\Support\CampusSeeder;

/**
 * Odd 2026 term registrations: most approved, a few waiting for the office, one rejected.
 */
class RegistrationSeeder extends CampusSeeder
{
    protected function seed(): void
    {
        $term = Term::query()->where('name', TeachingSeeder::CURRENT_TERM)->firstOrFail();

        if (TermRegistration::query()->where('term_id', $term->id)->exists()) {
            return;
        }

        $registrations = app(RegistrationService::class);
        $office = $this->user('office@'.self::DOMAIN);

        foreach (Student::query()->orderBy('id')->get() as $index => $student) {
            try {
                $registration = $this->as($student->user, fn () => $registrations->register($student, $term, []));
            } catch (BusinessRuleException) {
                continue; // graduated, dropped out, or nothing offered to the section
            }

            if ($index % 23 === 5) {
                continue; // still waiting for the office
            }

            $reject = $index % 61 === 7;
            $this->as($office, fn () => $registrations->decide($registration, ! $reject, $reject ? 'Fee for the semester is not paid yet. Register again after payment.' : null, $this->fresh($office)));
        }
    }
}
