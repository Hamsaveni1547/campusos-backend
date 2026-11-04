<?php

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Finance\Models\FeeConcession;
use App\Modules\Finance\Models\FeeDemand;
use App\Modules\Finance\Models\FeeHead;
use App\Modules\Finance\Models\FeePayment;
use App\Modules\Finance\Models\FeePaymentAllocation;
use App\Modules\Finance\Models\FeeStructure;
use App\Modules\Institution\Models\Term;
use App\Modules\Students\Models\Student;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Numbering\NumberGenerator;
use App\Shared\Settings\SettingsRepository;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fee structures, demands, concessions, payments with receipts, and student accounts.
 *
 * Nothing is edited in place: a concession or payment changes a demand's totals, a cancelled
 * receipt is kept and its amounts are taken back off the demands it paid.
 */
class FinanceService
{
    public function __construct(
        private NumberGenerator $numbers,
        private SettingsRepository $settings,
        private CurrentInstitution $currentInstitution,
    ) {}

    // ----- structures and demands ------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{fee_head_id: int, amount: float}>  $items
     */
    public function saveStructure(array $data, array $items, ?FeeStructure $structure = null): FeeStructure
    {
        if (count(array_unique(array_column($items, 'fee_head_id'))) !== count($items)) {
            throw new BusinessRuleException('Each fee head can appear only once.', 'DUPLICATE_HEAD', 422);
        }

        return DB::transaction(function () use ($data, $items, $structure): FeeStructure {
            $structure ??= new FeeStructure;
            $structure->fill($data)->save();
            $structure->items()->delete();

            foreach ($items as $item) {
                $structure->items()->create($item);
            }

            return $structure->load('items.head');
        });
    }

    /**
     * Raises a demand for every enrolled student the structure applies to (once per student).
     */
    public function generate(FeeStructure $structure, ?Term $term): int
    {
        $students = Student::query()
            ->where('programme_id', $structure->programme_id)
            ->whereNotIn('status', ['graduated', 'dropped_out', 'transferred_out'])
            ->when($structure->admission_year, fn ($q, $year) => $q->whereHas('batch', fn ($b) => $b->where('admission_year', $year)))
            ->when($structure->quota, fn ($q, $quota) => $q->where('admission_type', $quota))
            ->when($structure->term_number, fn ($q, $number) => $q->where('current_term_number', $number))
            ->whereNotIn('id', FeeDemand::query()->where('fee_structure_id', $structure->id)->where('status', '!=', 'cancelled')->select('student_id'))
            ->get();
        $structure->loadMissing('items');

        DB::transaction(function () use ($students, $structure, $term): void {
            foreach ($students as $student) {
                $this->raise($student, $structure->name, $structure->due_on->toDateString(), $structure->items->map(fn ($i): array => ['fee_head_id' => $i->fee_head_id, 'amount' => (float) $i->amount])->all(), $structure->id, $term?->id);
            }
        });

        return $students->count();
    }

    /**
     * A one-off charge, e.g. a fine or a certificate fee.
     *
     * @param  list<array{fee_head_id: int, amount: float}>  $items
     */
    public function raise(Student $student, string $title, string $dueOn, array $items, ?int $structureId = null, ?int $termId = null): FeeDemand
    {
        $total = round(array_sum(array_column($items, 'amount')), 2);

        if ($total <= 0) {
            throw new BusinessRuleException('The amount must be more than zero.', 'AMOUNT_INVALID', 422);
        }

        return DB::transaction(function () use ($student, $title, $dueOn, $items, $structureId, $termId, $total): FeeDemand {
            $demand = FeeDemand::query()->create(['student_id' => $student->id, 'fee_structure_id' => $structureId, 'term_id' => $termId, 'title' => $title, 'due_on' => $dueOn, 'amount' => $total]);
            $demand->forceFill(['status' => 'open'])->save();

            foreach ($items as $item) {
                $demand->items()->create($item);
            }

            return $demand;
        });
    }

    public function cancelDemand(FeeDemand $demand, string $reason): void
    {
        if ((float) $demand->paid > 0) {
            throw new BusinessRuleException('Payments were made against this demand. Cancel those receipts first.', 'DEMAND_PAID', 409);
        }

        if ($demand->status === 'cancelled') {
            throw new BusinessRuleException('This demand is already cancelled.', 'INVALID_STATUS', 409);
        }

        $demand->forceFill(['status' => 'cancelled', 'cancel_reason' => $reason])->save();
        $demand->concessions()->where('status', 'pending')->update(['status' => 'rejected', 'remarks' => 'Demand cancelled']);
    }

    // ----- concessions -----------------------------------------------------

    public function requestConcession(FeeDemand $demand, float $amount, string $reason): FeeConcession
    {
        if ($demand->status === 'cancelled' || $amount <= 0 || $amount > $demand->balance()) {
            throw new BusinessRuleException('The concession must be more than zero and not more than the balance ('.number_format($demand->balance(), 2).').', 'AMOUNT_INVALID', 422);
        }

        if ($demand->concessions()->where('status', 'pending')->exists()) {
            throw new BusinessRuleException('A concession for this demand is already waiting.', 'CONCESSION_PENDING', 409);
        }

        return FeeConcession::query()->create(['fee_demand_id' => $demand->id, 'amount' => $amount, 'reason' => $reason])->refresh();
    }

    public function decideConcession(FeeConcession $concession, bool $approve, ?string $remarks, User $user): void
    {
        if ($concession->status !== 'pending') {
            throw new BusinessRuleException('This concession has already been decided.', 'ALREADY_REVIEWED', 409);
        }

        if ($concession->created_by === $user->id) {
            throw new BusinessRuleException('Someone other than the person who asked must decide.', 'SAME_PERSON', 409);
        }

        if (! $approve && blank($remarks)) {
            throw new BusinessRuleException('Say why the concession is rejected.', 'REMARKS_REQUIRED', 422);
        }

        DB::transaction(function () use ($concession, $approve, $remarks, $user): void {
            $demand = $concession->demand;

            if ($approve) {
                if ((float) $concession->amount > $demand->balance()) {
                    throw new BusinessRuleException('The balance is now less than this concession.', 'AMOUNT_INVALID', 422);
                }

                $demand->concession = (string) round((float) $demand->concession + (float) $concession->amount, 2);
                $demand->refreshStatus();
                $demand->save();
            }

            $concession->forceFill(['status' => $approve ? 'approved' : 'rejected', 'remarks' => $remarks, 'decided_by' => $user->id, 'decided_at' => now()])->save();
        });
    }

    // ----- payments --------------------------------------------------------

    /**
     * Records a payment and applies it to the student's demands, earliest due first
     * (or only to `$demandIds` when given). Paying more than is due is refused.
     *
     * @param  list<int>|null  $demandIds
     */
    public function collect(Student $student, float $amount, string $mode, ?string $reference, string $paidOn, ?array $demandIds = null): FeePayment
    {
        if ($amount <= 0) {
            throw new BusinessRuleException('The amount must be more than zero.', 'AMOUNT_INVALID', 422);
        }

        if ($mode !== 'cash' && blank($reference)) {
            throw new BusinessRuleException('Enter the cheque, DD or transaction reference.', 'REFERENCE_REQUIRED', 422);
        }

        $demands = $this->outstanding($student)->when($demandIds !== null, fn (Collection $c) => $c->whereIn('id', $demandIds))->values();
        $due = round($demands->sum(fn (FeeDemand $d): float => $d->balance()), 2);

        if ($amount > $due) {
            throw new BusinessRuleException('Only '.number_format($due, 2).' is due.', 'OVERPAYMENT', 422, ['due' => $due]);
        }

        return DB::transaction(function () use ($student, $amount, $mode, $reference, $paidOn, $demands): FeePayment {
            $institution = $this->currentInstitution->require();
            $payment = new FeePayment(['student_id' => $student->id, 'amount' => $amount, 'mode' => $mode, 'reference' => $reference, 'paid_on' => $paidOn]);
            $payment->forceFill([
                'status' => 'valid',
                'receipt_no' => $this->numbers->next('receipt_no', (string) $this->settings->get('finance', 'receipt_no_format'), $institution->id, tokens: ['INST' => $institution->code]),
            ])->save();

            $left = $amount;

            foreach ($demands as $demand) {
                if ($left <= 0) {
                    break;
                }

                $part = round(min($left, $demand->balance()), 2);
                FeePaymentAllocation::query()->create(['fee_payment_id' => $payment->id, 'fee_demand_id' => $demand->id, 'amount' => $part]);
                $demand->paid = (string) round((float) $demand->paid + $part, 2);
                $demand->refreshStatus();
                $demand->save();
                $left = round($left - $part, 2);
            }

            return $payment;
        });
    }

    /**
     * Cancels a receipt: the receipt stays (marked cancelled) and its amounts come off the demands.
     */
    public function cancelPayment(FeePayment $payment, string $reason, User $user): void
    {
        if ($payment->status === 'cancelled') {
            throw new BusinessRuleException('This receipt is already cancelled.', 'INVALID_STATUS', 409);
        }

        DB::transaction(function () use ($payment, $reason, $user): void {
            foreach ($payment->allocations()->with('demand')->get() as $allocation) {
                $demand = $allocation->demand;
                $demand->paid = (string) round((float) $demand->paid - (float) $allocation->amount, 2);
                $demand->refreshStatus();
                $demand->save();
            }

            $payment->forceFill(['status' => 'cancelled', 'cancel_reason' => $reason, 'cancelled_by' => $user->id, 'cancelled_at' => now()])->save();
        });
    }

    // ----- accounts --------------------------------------------------------

    /**
     * @return Collection<int, FeeDemand>
     */
    public function outstanding(Student $student): Collection
    {
        return FeeDemand::query()->where('student_id', $student->id)->whereIn('status', ['open', 'partly_paid'])->orderBy('due_on')->orderBy('id')->get();
    }

    /**
     * @return array{billed: float, concession: float, paid: float, balance: float, overdue: float, no_dues: bool}
     */
    public function totals(Student $student): array
    {
        $demands = FeeDemand::query()->where('student_id', $student->id)->where('status', '!=', 'cancelled')->get();
        $balance = round($demands->sum(fn (FeeDemand $d): float => $d->balance()), 2);

        return [
            'billed' => round((float) $demands->sum('amount'), 2),
            'concession' => round((float) $demands->sum('concession'), 2),
            'paid' => round((float) $demands->sum('paid'), 2),
            'balance' => $balance,
            'overdue' => round($demands->filter(fn (FeeDemand $d): bool => $d->due_on->isPast() && ! $d->due_on->isToday())->sum(fn (FeeDemand $d): float => $d->balance()), 2),
            'no_dues' => $balance <= 0,
        ];
    }

    public function headId(string $ulid): int
    {
        return FeeHead::query()->where('ulid', $ulid)->firstOrFail()->id;
    }
}
