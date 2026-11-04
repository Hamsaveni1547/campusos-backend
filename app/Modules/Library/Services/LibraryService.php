<?php

namespace App\Modules\Library\Services;

use App\Models\User;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use App\Modules\Library\Models\LibraryCopy;
use App\Modules\Library\Models\LibraryLoan;
use App\Modules\Students\Models\Student;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;

/**
 * Circulation: members (students and staff), issue, return, renew, fines and library dues.
 */
class LibraryService
{
    public function __construct(private SettingsRepository $settings) {}

    /**
     * Finds a member by email, student register number or employee code.
     *
     * @return array{user: User, type: string}
     */
    public function member(string $identifier): array
    {
        $identifier = trim($identifier);
        $student = Student::query()->with('user')->where('register_no', $identifier)->orWhereHas('user', fn ($q) => $q->where('email', $identifier))->first();

        if ($student !== null && $student->status->isEnrolled()) {
            return ['user' => $student->user, 'type' => 'student'];
        }

        $employee = Employee::query()->with('user')->where('employee_code', $identifier)->orWhereHas('user', fn ($q) => $q->where('email', $identifier))->first();

        if ($employee !== null && in_array($employee->status, [EmployeeStatus::Active, EmployeeStatus::OnLeave], true)) {
            return ['user' => $employee->user, 'type' => 'staff'];
        }

        throw new BusinessRuleException('No current student or staff member matches '.$identifier.'.', 'MEMBER_NOT_FOUND', 404);
    }

    public function issue(string $accessionNo, string $member): LibraryLoan
    {
        $copy = $this->copy($accessionNo);
        ['user' => $user, 'type' => $type] = $this->member($member);

        if ($copy->status !== 'available') {
            throw new BusinessRuleException('This copy is '.$copy->status.'.', 'COPY_UNAVAILABLE', 409);
        }

        $dues = $this->dues($user);

        if ($dues['fines'] > 0) {
            throw new BusinessRuleException('Pay the pending fine of ₹'.number_format($dues['fines'], 2).' first.', 'FINE_PENDING', 409);
        }

        $limit = (int) $this->settings->get('library', $type.'_limit');

        if ($dues['on_loan'] >= $limit) {
            throw new BusinessRuleException('The limit of '.$limit.' books is reached.', 'LIMIT_REACHED', 409);
        }

        return DB::transaction(function () use ($copy, $user, $type): LibraryLoan {
            $copy->forceFill(['status' => 'issued'])->save();

            $loan = LibraryLoan::query()->create([
                'library_copy_id' => $copy->id, 'user_id' => $user->id, 'member_type' => $type,
                'issued_on' => today()->toDateString(), 'due_on' => today()->addDays((int) $this->settings->get('library', $type.'_days'))->toDateString(),
            ]);
            $loan->forceFill(['fine_status' => 'none', 'renewals' => 0])->save();

            return $loan;
        });
    }

    /**
     * Returns a copy; a late return is charged the daily fine.
     */
    public function return(string $accessionNo): LibraryLoan
    {
        $copy = $this->copy($accessionNo);
        $loan = LibraryLoan::query()->where('library_copy_id', $copy->id)->whereNull('returned_on')->first()
            ?? throw new BusinessRuleException('This copy is not on loan.', 'NOT_ON_LOAN', 409);

        $daysLate = max(0, (int) $loan->due_on->diffInDays(today(), false));
        $fine = $daysLate * (int) $this->settings->get('library', 'fine_per_day');

        DB::transaction(function () use ($loan, $copy, $fine, $daysLate): void {
            $loan->forceFill([
                'returned_on' => today(), 'fine' => $fine, 'fine_status' => $fine > 0 ? 'due' : 'none',
                'fine_note' => $fine > 0 ? $daysLate.' day(s) late' : null,
            ])->save();
            $copy->forceFill(['status' => 'available'])->save();
        });

        return $loan;
    }

    public function renew(LibraryLoan $loan): LibraryLoan
    {
        if ($loan->returned_on !== null) {
            throw new BusinessRuleException('This book has been returned.', 'NOT_ON_LOAN', 409);
        }

        if ($loan->isOverdue()) {
            throw new BusinessRuleException('Overdue books must be returned at the counter.', 'OVERDUE', 409);
        }

        if ($loan->renewals >= (int) $this->settings->get('library', 'max_renewals')) {
            throw new BusinessRuleException('This loan cannot be renewed again.', 'RENEWAL_LIMIT', 409);
        }

        $loan->forceFill(['due_on' => today()->addDays((int) $this->settings->get('library', $loan->member_type.'_days')), 'renewals' => $loan->renewals + 1])->save();

        return $loan;
    }

    /**
     * Settle a fine: `paid` at the counter or `waived` (needs the waive permission).
     */
    public function settleFine(LibraryLoan $loan, string $how, ?string $note): void
    {
        if ($loan->fine_status !== 'due') {
            throw new BusinessRuleException('There is no fine due on this loan.', 'NO_FINE', 409);
        }

        if ($how === 'waived' && blank($note)) {
            throw new BusinessRuleException('Say why the fine is waived.', 'REMARKS_REQUIRED', 422);
        }

        $loan->forceFill(['fine_status' => $how, 'fine_note' => trim(($loan->fine_note ?? '').($note ? ' · '.$note : ''))])->save();
    }

    /**
     * @return array{on_loan: int, overdue: int, fines: float, no_dues: bool}
     */
    public function dues(User $user): array
    {
        $loans = LibraryLoan::query()->where('user_id', $user->id)->where(fn ($q) => $q->whereNull('returned_on')->orWhere('fine_status', 'due'))->get();
        $onLoan = $loans->whereNull('returned_on');
        $fines = (float) $loans->where('fine_status', 'due')->sum('fine');

        return [
            'on_loan' => $onLoan->count(),
            'overdue' => $onLoan->filter(fn (LibraryLoan $l): bool => $l->isOverdue())->count(),
            'fines' => $fines,
            'no_dues' => $onLoan->isEmpty() && $fines <= 0,
        ];
    }

    private function copy(string $accessionNo): LibraryCopy
    {
        return LibraryCopy::query()->with('book')->where('accession_no', trim($accessionNo))->first()
            ?? throw new BusinessRuleException('No copy with accession number '.$accessionNo.'.', 'COPY_NOT_FOUND', 404);
    }
}
