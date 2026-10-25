<?php

namespace App\Modules\Institution\Services;

use App\Modules\Institution\Enums\PeriodStatus;
use App\Modules\Institution\Models\AcademicYear;
use App\Modules\Institution\Models\Term;
use App\Shared\Exceptions\BusinessRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rules for academic years and terms: no overlapping dates, terms inside their
 * year, exactly one current year and one current term.
 */
class AcademicCalendar
{
    public function current(): ?AcademicYear
    {
        return AcademicYear::query()->where('is_current', true)->first();
    }

    public function currentTerm(): ?Term
    {
        return Term::query()->with('academicYear')->where('is_current', true)->first();
    }

    public function assertYearDates(Carbon $startsOn, Carbon $endsOn, ?AcademicYear $ignore = null): void
    {
        if ($endsOn->lte($startsOn)) {
            throw new BusinessRuleException('The end date must be after the start date.', 'INVALID_DATES');
        }

        $overlap = AcademicYear::query()
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore->id))
            ->where('starts_on', '<=', $endsOn)
            ->where('ends_on', '>=', $startsOn)
            ->exists();

        if ($overlap) {
            throw new BusinessRuleException('These dates overlap another academic year.', 'ACADEMIC_YEAR_OVERLAP', 409);
        }

        if ($ignore !== null) {
            $outside = $ignore->terms()->where(fn ($query) => $query->where('starts_on', '<', $startsOn)->orWhere('ends_on', '>', $endsOn))->exists();

            if ($outside) {
                throw new BusinessRuleException('Some terms of this year fall outside the new dates.', 'TERMS_OUTSIDE_YEAR', 409);
            }
        }
    }

    public function assertTermDates(AcademicYear $year, Carbon $startsOn, Carbon $endsOn, ?Term $ignore = null): void
    {
        if ($endsOn->lte($startsOn)) {
            throw new BusinessRuleException('The end date must be after the start date.', 'INVALID_DATES');
        }

        if ($startsOn->lt($year->starts_on) || $endsOn->gt($year->ends_on)) {
            throw new BusinessRuleException("The term must fall within {$year->name} ({$year->starts_on->toDateString()} to {$year->ends_on->toDateString()}).", 'TERM_OUTSIDE_YEAR', 422);
        }

        $overlap = Term::query()
            ->where('academic_year_id', $year->id)
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore->id))
            ->where('starts_on', '<=', $endsOn)
            ->where('ends_on', '>=', $startsOn)
            ->exists();

        if ($overlap) {
            throw new BusinessRuleException('These dates overlap another term of the same year.', 'TERM_OVERLAP', 409);
        }
    }

    public function makeYearCurrent(AcademicYear $year): AcademicYear
    {
        if ($year->status === PeriodStatus::Closed) {
            throw new BusinessRuleException('A closed academic year cannot be made current.', 'ACADEMIC_YEAR_CLOSED', 409);
        }

        DB::transaction(function () use ($year): void {
            AcademicYear::query()->whereKeyNot($year->id)->where('is_current', true)->update(['is_current' => false]);
            $year->update(['is_current' => true, 'status' => PeriodStatus::Active]);
        });

        return $year;
    }

    public function makeTermCurrent(Term $term): Term
    {
        if ($term->status === PeriodStatus::Closed) {
            throw new BusinessRuleException('A closed term cannot be made current.', 'TERM_CLOSED', 409);
        }

        DB::transaction(function () use ($term): void {
            Term::query()->whereKeyNot($term->id)->where('is_current', true)->update(['is_current' => false]);
            $term->update(['is_current' => true, 'status' => PeriodStatus::Active]);

            if (! $term->academicYear->is_current) {
                $this->makeYearCurrent($term->academicYear);
            }
        });

        return $term->refresh();
    }

    public function closeYear(AcademicYear $year): AcademicYear
    {
        if ($year->terms()->where('status', '!=', PeriodStatus::Closed)->exists()) {
            throw new BusinessRuleException('Close every term of this year first.', 'OPEN_TERMS', 409);
        }

        $year->update(['status' => PeriodStatus::Closed, 'is_current' => false]);

        return $year;
    }

    public function closeTerm(Term $term): Term
    {
        $term->update(['status' => PeriodStatus::Closed, 'is_current' => false]);

        return $term;
    }
}
