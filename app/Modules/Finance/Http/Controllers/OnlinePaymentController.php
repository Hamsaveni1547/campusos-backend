<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Models\User;
use App\Modules\Finance\Models\FeeDemand;
use App\Modules\Finance\Models\OnlinePayment;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Students\Models\Student;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Online fee payment through a simulated gateway (demo): the student chooses whether the payment
 * succeeds or fails, and a success is recorded as a normal fee receipt.
 *
 * @tags Fees
 */
class OnlinePaymentController extends Controller
{
    public function __construct(private FinanceService $finance) {}

    /**
     * My online payment attempts, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $student = $this->student($request);

        return ApiResponse::list(OnlinePayment::query()->with('payment')->where('student_id', $student->id)->latest('id')->limit(20)->get(), fn (OnlinePayment $p): array => $this->present($p));
    }

    /**
     * Start a payment: `amount` (default: everything due), `demand_ids[]` (optional).
     */
    public function start(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $validated = $request->validate(['amount' => ['nullable', 'numeric', 'min:1'], 'demand_ids' => ['nullable', 'array'], 'demand_ids.*' => ['string', 'size:26']]);

        $demandIds = isset($validated['demand_ids']) ? FeeDemand::query()->where('student_id', $student->id)->whereIn('ulid', $validated['demand_ids'])->pluck('id')->all() : null;
        $due = round($this->finance->outstanding($student)->when($demandIds !== null, fn ($c) => $c->whereIn('id', $demandIds))->sum(fn (FeeDemand $d): float => $d->balance()), 2);
        $amount = round((float) ($validated['amount'] ?? $due), 2);

        if ($due <= 0) {
            throw new BusinessRuleException('Nothing is due.', 'NOTHING_DUE', 422);
        }
        if ($amount > $due) {
            throw new BusinessRuleException('Only '.number_format($due, 2).' is due.', 'OVERPAYMENT', 422, ['due' => $due]);
        }

        $payment = OnlinePayment::query()->create(['student_id' => $student->id, 'amount' => $amount, 'demand_ids' => $demandIds]);

        return ApiResponse::data($this->present($payment->refresh()), 201);
    }

    /**
     * Finish a payment on the simulated gateway: `method` upi|card|netbanking, `outcome` success|failure.
     */
    public function complete(Request $request, OnlinePayment $onlinePayment): JsonResponse
    {
        $student = $this->student($request);
        abort_unless($onlinePayment->student_id === $student->id, 404);
        $validated = $request->validate(['method' => ['required', Rule::in(OnlinePayment::METHODS)], 'outcome' => ['required', Rule::in(['success', 'failure'])]]);

        if ($onlinePayment->status !== 'pending') {
            throw new BusinessRuleException('This payment is already finished. Start a new one.', 'ALREADY_COMPLETED', 422);
        }

        $reference = 'SIM'.strtoupper(Str::random(10));

        if ($validated['outcome'] === 'failure') {
            $onlinePayment->forceFill(['method' => $validated['method'], 'status' => 'failed', 'gateway_ref' => $reference, 'failure_reason' => 'Declined by the bank (simulated).', 'completed_at' => now()])->save();

            return ApiResponse::data($this->present($onlinePayment));
        }

        DB::transaction(function () use ($onlinePayment, $student, $validated, $reference): void {
            $receipt = $this->finance->collect($student, (float) $onlinePayment->amount, $validated['method'] === 'card' ? 'card' : ($validated['method'] === 'upi' ? 'upi' : 'neft'), 'Online '.$reference, today()->toDateString(), $onlinePayment->demand_ids);
            $onlinePayment->forceFill(['method' => $validated['method'], 'status' => 'success', 'gateway_ref' => $reference, 'fee_payment_id' => $receipt->id, 'completed_at' => now()])->save();
        });

        return ApiResponse::data($this->present($onlinePayment->load('payment')));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(OnlinePayment $p): array
    {
        return [
            'id' => $p->ulid,
            'amount' => (float) $p->amount,
            'method' => $p->method,
            'status' => $p->status,
            'gateway_ref' => $p->gateway_ref,
            'failure_reason' => $p->failure_reason,
            'receipt' => $p->payment ? ['id' => $p->payment->ulid, 'receipt_no' => $p->payment->receipt_no] : null,
            'created_at' => $p->created_at?->toIso8601String(),
            'completed_at' => $p->completed_at?->toIso8601String(),
        ];
    }

    private function student(Request $request): Student
    {
        /** @var User $user */
        $user = $request->user();

        return Student::query()->where('user_id', $user->id)->first() ?? throw new BusinessRuleException('You do not have a student record in this institution.', 'NOT_A_STUDENT', 404);
    }
}
