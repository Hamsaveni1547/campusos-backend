<?php

namespace App\Modules\Academics\Services;

use App\Models\User;
use App\Modules\Academics\Enums\RegistrationStatus;
use App\Modules\Academics\Models\CourseOffering;
use App\Modules\Academics\Models\CourseRegistration;
use App\Modules\Academics\Models\TermRegistration;
use App\Modules\Institution\Enums\PeriodStatus;
use App\Modules\Institution\Models\Term;
use App\Modules\Students\Models\Student;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Settings\SettingsRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Term registration: core courses come from the student's section; electives are chosen
 * within capacity and the term credit limit; staff approve or reject.
 */
class RegistrationService
{
    public function __construct(private SettingsRepository $settings) {}

    /**
     * Offerings available to a student this term, split into core and elective.
     *
     * @return array{core: Collection<int, CourseOffering>, electives: Collection<int, CourseOffering>}
     */
    public function available(Student $student, Term $term): array
    {
        if ($student->section_id === null) {
            return ['core' => new Collection, 'electives' => new Collection];
        }

        $offerings = CourseOffering::query()
            ->with('course')
            ->where('term_id', $term->id)
            ->where('status', 'active')
            ->whereHas('sections', fn ($query) => $query->where('section_id', $student->section_id))
            ->get();

        return [
            'core' => $offerings->where('is_elective', false)->values(),
            'electives' => $offerings->where('is_elective', true)->values(),
        ];
    }

    /**
     * Seats taken in an elective by submitted or approved registrations.
     */
    public function taken(CourseOffering $offering): int
    {
        return CourseRegistration::query()
            ->where('course_offering_id', $offering->id)
            ->whereHas('registration', fn ($query) => $query->where('status', '!=', RegistrationStatus::Rejected->value))
            ->count();
    }

    /**
     * Register (or change a rejected registration) with the chosen electives.
     *
     * @param  list<int>  $electiveIds  course offering ids
     */
    public function register(Student $student, Term $term, array $electiveIds): TermRegistration
    {
        if (! $student->status->isEnrolled() || $student->section_id === null) {
            throw new BusinessRuleException('Only enrolled students placed in a section can register.', 'NOT_ELIGIBLE', 422);
        }

        if (! $term->is_current || $term->status === PeriodStatus::Closed) {
            throw new BusinessRuleException('Registration is open only for the current term.', 'TERM_NOT_OPEN', 409);
        }

        $existing = TermRegistration::query()->where('student_id', $student->id)->where('term_id', $term->id)->first();

        if ($existing !== null && $existing->status !== RegistrationStatus::Rejected) {
            throw new BusinessRuleException('You have already registered for this term.', 'ALREADY_REGISTERED', 409);
        }

        ['core' => $core, 'electives' => $electives] = $this->available($student, $term);

        if ($core->isEmpty() && $electives->isEmpty()) {
            throw new BusinessRuleException('No courses are offered to your section yet.', 'NO_COURSES', 422);
        }

        $chosen = $electives->whereIn('id', $electiveIds);

        if ($chosen->count() !== count(array_unique($electiveIds))) {
            throw new BusinessRuleException('One of the electives is not offered to your section.', 'ELECTIVE_INVALID', 422);
        }

        $credits = (float) $core->sum(fn (CourseOffering $offering): float => (float) $offering->course->credits) + (float) $chosen->sum(fn (CourseOffering $offering): float => (float) $offering->course->credits);
        $limit = (float) $this->settings->get('academics', 'max_credits_per_term');

        if ($limit > 0 && $credits > $limit) {
            throw new BusinessRuleException("These courses add up to {$credits} credits; the limit is {$limit}.", 'CREDIT_LIMIT', 422, ['credits' => $credits, 'limit' => $limit]);
        }

        return DB::transaction(function () use ($student, $term, $core, $chosen, $credits, $existing): TermRegistration {
            foreach ($chosen as $elective) {
                /** @var CourseOffering $locked */
                $locked = CourseOffering::query()->lockForUpdate()->findOrFail($elective->id);
                $taken = $this->taken($locked) - ($existing !== null && $existing->courses()->where('course_offering_id', $locked->id)->exists() ? 1 : 0);

                if ($locked->capacity !== null && $taken >= $locked->capacity) {
                    throw new BusinessRuleException($elective->course->title.' is full.', 'ELECTIVE_FULL', 409, ['offering' => $elective->ulid]);
                }
            }

            $registration = $existing ?? new TermRegistration(['student_id' => $student->id, 'term_id' => $term->id]);
            $registration->fill(['section_id' => $student->section_id, 'term_number' => $student->current_term_number, 'total_credits' => $credits]);
            $registration->forceFill(['status' => RegistrationStatus::Submitted, 'submitted_at' => now(), 'remarks' => null, 'decided_by' => null, 'decided_at' => null])->save();

            $registration->courses()->delete();

            foreach ([...$core->all(), ...$chosen->all()] as $offering) {
                $registration->courses()->create([
                    'institution_id' => $registration->institution_id,
                    'course_offering_id' => $offering->id,
                    'type' => $offering->is_elective ? 'elective' : 'core',
                ]);
            }

            return $registration;
        });
    }

    public function decide(TermRegistration $registration, bool $approve, ?string $remarks, User $staff): TermRegistration
    {
        if ($registration->status !== RegistrationStatus::Submitted) {
            throw new BusinessRuleException('Only submitted registrations can be decided.', 'INVALID_STATUS', 409);
        }

        if (! $approve && blank($remarks)) {
            throw new BusinessRuleException('Say why the registration is rejected.', 'REMARKS_REQUIRED', 422);
        }

        $registration->forceFill([
            'status' => $approve ? RegistrationStatus::Approved : RegistrationStatus::Rejected,
            'remarks' => $remarks,
            'decided_by' => $staff->id,
            'decided_at' => now(),
        ])->save();

        return $registration;
    }
}
