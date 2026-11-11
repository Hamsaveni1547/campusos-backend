<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Models\User;
use App\Modules\Admissions\Enums\Quota;
use App\Modules\Finance\Authorization\FinancePermissions as P;
use App\Modules\Finance\Models\FeeConcession;
use App\Modules\Finance\Models\FeeDemand;
use App\Modules\Finance\Models\FeeHead;
use App\Modules\Finance\Models\FeePayment;
use App\Modules\Finance\Models\FeeStructure;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Institution\Models\Programme;
use App\Modules\Institution\Models\Term;
use App\Modules\Students\Models\Student;
use App\Shared\Documents\DocumentPdf;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Http\ApiResponse;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * @tags Fees
 */
class FinanceController extends Controller
{
    public function __construct(private FinanceService $finance) {}

    /**
     * Payment modes, demand statuses, quotas and fee heads for forms.
     */
    public function options(): JsonResponse
    {
        $labels = fn (array $values): array => array_map(fn (string $v): array => ['value' => $v, 'label' => in_array($v, ['dd', 'neft', 'upi'], true) ? strtoupper($v) : ucfirst(str_replace('_', ' ', $v))], $values);

        return ApiResponse::data([
            'modes' => $labels(FeePayment::MODES),
            'demand_statuses' => $labels(['open', 'partly_paid', 'paid', 'cancelled']),
            'quotas' => collect(Quota::cases())->map(fn (Quota $q): array => ['value' => $q->value, 'label' => $q->label()])->values(),
            'heads' => FeeHead::query()->where('status', 'active')->orderBy('name')->get()->map(fn (FeeHead $h): array => ['value' => $h->ulid, 'label' => $h->name])->values(),
        ]);
    }

    // ----- heads -----------------------------------------------------------

    public function heads(): JsonResponse
    {
        Gate::authorize(P::StructuresManage);

        return ApiResponse::list(FeeHead::query()->orderBy('code')->get(), fn (FeeHead $h): array => $this->head($h));
    }

    public function storeHead(Request $request): JsonResponse
    {
        return $this->saveHead($request, null);
    }

    public function updateHead(Request $request, FeeHead $head): JsonResponse
    {
        return $this->saveHead($request, $head);
    }

    private function saveHead(Request $request, ?FeeHead $head): JsonResponse
    {
        Gate::authorize(P::StructuresManage);
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('fee_heads', 'code')->where('institution_id', app(CurrentInstitution::class)->id())->ignore($head?->id)],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        $head ??= new FeeHead;
        $head->fill($validated)->save();

        return ApiResponse::data($this->head($head), $head->wasRecentlyCreated ? 201 : 200);
    }

    // ----- structures ------------------------------------------------------

    /**
     * Fee structures. Filter: `filter[programme]`.
     */
    public function structures(Request $request): JsonResponse
    {
        Gate::authorize(P::ReportsView);
        $programme = $request->input('filter.programme');

        $structures = FeeStructure::query()->with(['programme', 'items.head'])->withCount(['demands' => fn ($q) => $q->where('status', '!=', 'cancelled')])
            ->when($programme, fn ($q, $ulid) => $q->whereHas('programme', fn ($p) => $p->where('ulid', $ulid)))
            ->latest('id')->get();

        return ApiResponse::list($structures, fn (FeeStructure $s): array => $this->structure($s));
    }

    public function storeStructure(Request $request): JsonResponse
    {
        return $this->saveStructure($request, null);
    }

    public function updateStructure(Request $request, FeeStructure $structure): JsonResponse
    {
        return $this->saveStructure($request, $structure);
    }

    private function saveStructure(Request $request, ?FeeStructure $structure): JsonResponse
    {
        Gate::authorize(P::StructuresManage);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'programme_id' => ['required', 'string', 'size:26'],
            'admission_year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'quota' => ['nullable', Rule::enum(Quota::class)],
            'term_number' => ['nullable', 'integer', 'min:1', 'max:20'],
            'due_on' => ['required', 'date'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'items' => ['required', 'array', 'min:1'],
            'items.*.fee_head_id' => ['required', 'string', 'size:26'],
            'items.*.amount' => ['required', 'numeric', 'min:1', 'max:10000000'],
        ]);

        $data = collect($validated)->except('items')->all();
        $data['programme_id'] = Programme::query()->where('ulid', $validated['programme_id'])->firstOrFail()->id;
        $items = array_map(fn (array $i): array => ['fee_head_id' => $this->finance->headId($i['fee_head_id']), 'amount' => (float) $i['amount']], $validated['items']);
        $created = $structure === null;
        $structure = $this->finance->saveStructure($data, $items, $structure);

        return ApiResponse::data($this->structure($structure->load('programme')), $created ? 201 : 200);
    }

    /**
     * Raise this structure's fees for every student it applies to (`term_id` optional).
     */
    public function generate(Request $request, FeeStructure $structure): JsonResponse
    {
        Gate::authorize(P::DemandsManage);
        $termUlid = $request->validate(['term_id' => ['nullable', 'string', 'size:26']])['term_id'] ?? null;
        $term = $termUlid ? Term::query()->where('ulid', $termUlid)->firstOrFail() : Term::query()->where('is_current', true)->first();
        $count = $this->finance->generate($structure, $term);

        return ApiResponse::data(['raised' => $count, 'message' => $count === 0 ? 'Everyone it applies to already has this fee.' : "Fees raised for {$count} students."]);
    }

    // ----- demands ---------------------------------------------------------

    /**
     * Demands. Filters: `filter[student]`, `filter[status]`, `filter[structure]`, `filter[overdue]` (1), `filter[search]`.
     */
    public function demands(Request $request): JsonResponse
    {
        Gate::authorize(P::ReportsView);
        $f = (array) $request->input('filter', []);

        $demands = FeeDemand::query()->with('student.user')
            ->when($f['student'] ?? null, fn ($q, $ulid) => $q->whereHas('student', fn ($s) => $s->where('ulid', $ulid)))
            ->when($f['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($f['structure'] ?? null, fn ($q, $ulid) => $q->whereIn('fee_structure_id', FeeStructure::query()->where('ulid', $ulid)->select('id')))
            ->when($f['overdue'] ?? null, fn ($q) => $q->whereIn('status', ['open', 'partly_paid'])->whereDate('due_on', '<', today()))
            ->when($f['search'] ?? null, fn ($q, $term) => $q->whereHas('student', fn ($s) => $s->where('register_no', 'like', "%{$term}%")->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$term}%"))))
            ->orderBy('due_on')->orderBy('id')->paginate(25);

        return ApiResponse::paginated($demands, fn (FeeDemand $d): array => $this->demand($d));
    }

    /**
     * One-off charge: `student_id`, `title`, `due_on`, `items[] {fee_head_id, amount}`.
     */
    public function raise(Request $request): JsonResponse
    {
        Gate::authorize(P::DemandsManage);
        $validated = $request->validate([
            'student_id' => ['required', 'string', 'size:26'],
            'title' => ['required', 'string', 'max:200'],
            'due_on' => ['required', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.fee_head_id' => ['required', 'string', 'size:26'],
            'items.*.amount' => ['required', 'numeric', 'min:1', 'max:10000000'],
        ]);
        $student = Student::query()->where('ulid', $validated['student_id'])->firstOrFail();
        $demand = $this->finance->raise($student, $validated['title'], (string) $validated['due_on'], array_map(fn (array $i): array => ['fee_head_id' => $this->finance->headId($i['fee_head_id']), 'amount' => (float) $i['amount']], $validated['items']));

        return ApiResponse::data($this->demand($demand->load('student.user')), 201);
    }

    public function cancelDemand(Request $request, FeeDemand $demand): JsonResponse
    {
        Gate::authorize(P::DemandsManage);
        $this->finance->cancelDemand($demand, (string) $request->validate(['reason' => ['required', 'string', 'max:500']])['reason']);

        return ApiResponse::message('Demand cancelled.');
    }

    // ----- concessions -----------------------------------------------------

    /**
     * Concessions. Filter: `filter[status]`.
     */
    public function concessions(Request $request): JsonResponse
    {
        Gate::authorize(P::ReportsView);
        $status = $request->input('filter.status');

        $concessions = FeeConcession::query()->with(['demand.student.user', 'requester'])
            ->when($status, fn ($q, $value) => $q->where('status', $value))->latest('id')->paginate(25);

        return ApiResponse::paginated($concessions, fn (FeeConcession $c): array => [
            'id' => $c->ulid,
            'demand' => ['id' => $c->demand->ulid, 'title' => $c->demand->title, 'amount' => (float) $c->demand->amount, 'balance' => $c->demand->balance()],
            'student' => $this->student($c->demand->student),
            'amount' => (float) $c->amount,
            'reason' => $c->reason,
            'status' => $c->status,
            'remarks' => $c->remarks,
            'requested_by' => $c->requester?->name,
            'requested_at' => $c->created_at?->toIso8601String(),
            'can_decide' => $c->status === 'pending' && $this->user($request)->can(P::ConcessionsApprove) && $c->created_by !== $this->user($request)->id,
        ]);
    }

    public function requestConcession(Request $request, FeeDemand $demand): JsonResponse
    {
        Gate::authorize(P::DemandsManage);
        $validated = $request->validate(['amount' => ['required', 'numeric', 'min:1'], 'reason' => ['required', 'string', 'max:500']]);
        $concession = $this->finance->requestConcession($demand, (float) $validated['amount'], $validated['reason']);

        return ApiResponse::data(['id' => $concession->ulid], 201);
    }

    public function decideConcession(Request $request, FeeConcession $concession): JsonResponse
    {
        Gate::authorize(P::ConcessionsApprove);
        $validated = $request->validate(['approve' => ['required', 'boolean'], 'remarks' => ['nullable', 'string', 'max:500']]);
        $this->finance->decideConcession($concession, (bool) $validated['approve'], $validated['remarks'] ?? null, $this->user($request));

        return ApiResponse::message($validated['approve'] ? 'Concession approved.' : 'Concession rejected.');
    }

    // ----- payments --------------------------------------------------------

    /**
     * Receipts. Filters: `filter[student]`, `filter[mode]`, `filter[status]`, `filter[from]`, `filter[to]`.
     */
    public function payments(Request $request): JsonResponse
    {
        Gate::authorize(P::ReportsView);
        $f = (array) $request->input('filter', []);

        $payments = FeePayment::query()->with(['student.user', 'receiver'])
            ->when($f['student'] ?? null, fn ($q, $ulid) => $q->whereHas('student', fn ($s) => $s->where('ulid', $ulid)))
            ->when($f['mode'] ?? null, fn ($q, $mode) => $q->where('mode', $mode))
            ->when($f['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($f['from'] ?? null, fn ($q, $from) => $q->whereDate('paid_on', '>=', $from))
            ->when($f['to'] ?? null, fn ($q, $to) => $q->whereDate('paid_on', '<=', $to))
            ->latest('paid_on')->latest('id')->paginate(25);

        return ApiResponse::paginated($payments, fn (FeePayment $p): array => $this->payment($p));
    }

    /**
     * Record a payment: `student_id`, `amount`, `mode`, `reference`, `paid_on`, `demand_ids?[]`.
     */
    public function collect(Request $request): JsonResponse
    {
        Gate::authorize(P::PaymentsCollect);
        $validated = $request->validate([
            'student_id' => ['required', 'string', 'size:26'],
            'amount' => ['required', 'numeric', 'min:1'],
            'mode' => ['required', Rule::in(FeePayment::MODES)],
            'reference' => ['nullable', 'string', 'max:100'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'demand_ids' => ['nullable', 'array'],
            'demand_ids.*' => ['string', 'size:26'],
        ]);
        $student = Student::query()->where('ulid', $validated['student_id'])->firstOrFail();
        $demandIds = isset($validated['demand_ids']) ? FeeDemand::query()->whereIn('ulid', $validated['demand_ids'])->where('student_id', $student->id)->pluck('id')->all() : null;
        $payment = $this->finance->collect($student, (float) $validated['amount'], $validated['mode'], $validated['reference'] ?? null, (string) $validated['paid_on'], $demandIds);

        return ApiResponse::data($this->receipt($payment->refresh()), 201);
    }

    /**
     * A receipt with what it paid.
     */
    public function receiptShow(Request $request, FeePayment $payment): JsonResponse
    {
        $user = $this->user($request);
        $own = Student::query()->where('user_id', $user->id)->value('id') === $payment->student_id;
        abort_unless($own || $user->can(P::ReportsView), 403);

        return ApiResponse::data($this->receipt($payment));
    }

    /**
     * The receipt as a PDF with a verification code (same access as the receipt).
     */
    public function receiptPdf(Request $request, FeePayment $payment, DocumentPdf $pdf): Response
    {
        $r = $this->receiptShow($request, $payment)->getData(true)['data'];

        return $pdf->download('receipt', $payment->ulid, 'Fee receipt', (string) $r['student']['name'], 'pdf.receipt', ['r' => $r],
            ['Receipt no.' => $r['receipt_no'], 'Amount' => 'Rs. '.number_format((float) $r['amount'], 2), 'Paid on' => $r['paid_on'], 'Status' => $r['status']], 'receipt-'.$r['receipt_no']);
    }

    public function cancelPayment(Request $request, FeePayment $payment): JsonResponse
    {
        Gate::authorize(P::PaymentsCancel);
        $this->finance->cancelPayment($payment, (string) $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'], $this->user($request));

        return ApiResponse::message('Receipt cancelled and the payment reversed.');
    }

    // ----- accounts and reports --------------------------------------------

    /**
     * A student's fee account: totals, demands and receipts.
     */
    public function account(Request $request, Student $student): JsonResponse
    {
        Gate::authorize(P::ReportsView);

        return ApiResponse::data($this->accountData($student));
    }

    /**
     * The signed-in student's fee account.
     */
    public function mine(Request $request): JsonResponse
    {
        $student = Student::query()->where('user_id', $this->user($request)->id)->first() ?? throw new BusinessRuleException('You do not have a student record in this institution.', 'NOT_A_STUDENT', 404);

        return ApiResponse::data($this->accountData($student));
    }

    /**
     * No-dues check for certificates and hall tickets.
     */
    public function noDues(Student $student): JsonResponse
    {
        Gate::authorize(P::ReportsView);
        $totals = $this->finance->totals($student);

        return ApiResponse::data(['no_dues' => $totals['no_dues'], 'balance' => $totals['balance']]);
    }

    /**
     * Collections between `from` and `to` (default this month): by day and by mode.
     */
    public function collections(Request $request): JsonResponse
    {
        Gate::authorize(P::ReportsView);
        $validated = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $from = Carbon::parse($validated['from'] ?? now()->startOfMonth())->toDateString();
        $to = Carbon::parse($validated['to'] ?? now())->toDateString();
        $payments = FeePayment::query()->where('status', 'valid')->whereBetween('paid_on', [$from, $to])->get();

        return ApiResponse::data([
            'from' => $from,
            'to' => $to,
            'total' => round((float) $payments->sum('amount'), 2),
            'receipts' => $payments->count(),
            'by_mode' => $payments->groupBy('mode')->map(fn ($g): float => round((float) $g->sum('amount'), 2))->all(),
            'by_day' => $payments->groupBy(fn (FeePayment $p): string => $p->paid_on->toDateString())->sortKeys()->map(fn ($g): float => round((float) $g->sum('amount'), 2))->all(),
        ]);
    }

    /**
     * Students with dues, largest first. Filters: `filter[programme]`, `filter[overdue]` (1).
     */
    public function dues(Request $request): JsonResponse
    {
        Gate::authorize(P::ReportsView);
        $f = (array) $request->input('filter', []);

        $rows = FeeDemand::query()->with('student.user', 'student.programme')
            ->whereIn('status', ['open', 'partly_paid'])
            ->when($f['programme'] ?? null, fn ($q, $ulid) => $q->whereHas('student.programme', fn ($p) => $p->where('ulid', $ulid)))
            ->when($f['overdue'] ?? null, fn ($q) => $q->whereDate('due_on', '<', today()))
            ->get()->groupBy('student_id')
            ->map(fn ($demands): array => [
                'student' => [...$this->student($demands->first()->student), 'programme' => $demands->first()->student->programme->code],
                'demands' => $demands->count(),
                'balance' => round($demands->sum(fn (FeeDemand $d): float => $d->balance()), 2),
                'oldest_due' => $demands->min('due_on')?->toDateString(),
            ])->sortByDesc('balance')->values();

        return ApiResponse::data(['total' => round((float) $rows->sum('balance'), 2), 'students' => $rows->count(), 'rows' => $rows->take(200)->values()]);
    }

    // ----- presenters ------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function accountData(Student $student): array
    {
        $student->loadMissing('user', 'programme');

        return [
            'student' => [...$this->student($student), 'programme' => $student->programme->name],
            'totals' => $this->finance->totals($student),
            'demands' => FeeDemand::query()->with('items.head')->where('student_id', $student->id)->orderByDesc('due_on')->get()->map(fn (FeeDemand $d): array => [
                ...$this->demand($d),
                'items' => $d->items->map(fn ($i): array => ['head' => $i->head->name, 'amount' => (float) $i->amount])->values(),
            ])->values(),
            'payments' => FeePayment::query()->where('student_id', $student->id)->latest('paid_on')->latest('id')->get()->map(fn (FeePayment $p): array => $this->payment($p, withStudent: false))->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function head(FeeHead $head): array
    {
        return ['id' => $head->ulid, 'code' => $head->code, 'name' => $head->name, 'description' => $head->description, 'status' => $head->status];
    }

    /**
     * @return array<string, mixed>
     */
    private function structure(FeeStructure $structure): array
    {
        $structure->loadMissing('items.head', 'programme');

        return [
            'id' => $structure->ulid,
            'name' => $structure->name,
            'programme' => ['id' => $structure->programme->ulid, 'code' => $structure->programme->code, 'name' => $structure->programme->name],
            'admission_year' => $structure->admission_year,
            'quota' => $structure->quota,
            'term_number' => $structure->term_number,
            'due_on' => $structure->due_on->toDateString(),
            'status' => $structure->status,
            'total' => $structure->total(),
            'items' => $structure->items->map(fn ($i): array => ['fee_head_id' => $i->head->ulid, 'head' => $i->head->name, 'amount' => (float) $i->amount])->values(),
            'demands' => $structure->demands_count ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function demand(FeeDemand $demand): array
    {
        return [
            'id' => $demand->ulid,
            'student' => $demand->relationLoaded('student') ? $this->student($demand->student) : null,
            'title' => $demand->title,
            'due_on' => $demand->due_on->toDateString(),
            'amount' => (float) $demand->amount,
            'concession' => (float) $demand->concession,
            'paid' => (float) $demand->paid,
            'balance' => $demand->balance(),
            'status' => $demand->status,
            'overdue' => in_array($demand->status, ['open', 'partly_paid'], true) && $demand->due_on->lt(today()),
            'cancel_reason' => $demand->cancel_reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payment(FeePayment $payment, bool $withStudent = true): array
    {
        return [
            'id' => $payment->ulid,
            'receipt_no' => $payment->receipt_no,
            'student' => $withStudent ? $this->student($payment->student) : null,
            'amount' => (float) $payment->amount,
            'mode' => $payment->mode,
            'reference' => $payment->reference,
            'paid_on' => $payment->paid_on->toDateString(),
            'status' => $payment->status,
            'cancel_reason' => $payment->cancel_reason,
            'received_by' => $payment->receiver?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function receipt(FeePayment $payment): array
    {
        $payment->loadMissing(['student.user', 'student.programme', 'receiver', 'allocations.demand']);

        return [
            ...$this->payment($payment),
            'programme' => $payment->student->programme->name,
            'paid_for' => $payment->allocations->map(fn ($a): array => ['demand' => $a->demand->title, 'amount' => (float) $a->amount])->values(),
            'balance_after' => $this->finance->totals($payment->student)['balance'],
        ];
    }

    /**
     * @return array{id: string, name: string|null, register_no: string|null}
     */
    private function student(Student $student): array
    {
        return ['id' => $student->ulid, 'name' => $student->user->name, 'register_no' => $student->register_no];
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
