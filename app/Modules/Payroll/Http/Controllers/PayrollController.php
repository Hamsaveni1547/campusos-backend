<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\User;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Authorization\PayrollPermissions as P;
use App\Modules\Payroll\Models\EmployeeSalary;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\PayrollService;
use App\Modules\Payroll\Support\PayrollCalculator;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Http\ApiResponse;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * @tags Payroll
 */
class PayrollController extends Controller
{
    public function __construct(private PayrollService $payroll) {}

    // ----- components ------------------------------------------------------

    public function components(): JsonResponse
    {
        Gate::authorize(P::ReportsView);

        return ApiResponse::list(PayComponent::query()->orderBy('position')->orderBy('id')->get(), fn (PayComponent $c): array => $this->component($c));
    }

    public function storeComponent(Request $request): JsonResponse
    {
        return $this->saveComponent($request, null);
    }

    public function updateComponent(Request $request, PayComponent $component): JsonResponse
    {
        return $this->saveComponent($request, $component);
    }

    private function saveComponent(Request $request, ?PayComponent $component): JsonResponse
    {
        Gate::authorize(P::SalariesManage);
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20', 'alpha_dash:ascii', Rule::unique('pay_components', 'code')->where('institution_id', app(CurrentInstitution::class)->id())->ignore($component?->id)],
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['required', Rule::in(['earning', 'deduction'])],
            'calculation' => ['required', Rule::in(['fixed', 'percent'])],
            'percent' => ['nullable', 'required_if:calculation,percent', 'numeric', 'min:0', 'max:100'],
            'max_amount' => ['nullable', 'numeric', 'min:0'],
            'affected_by_lop' => ['boolean'],
            'position' => ['nullable', 'integer', 'min:0', 'max:999'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
        $validated['code'] = strtoupper($validated['code']);

        if ($validated['code'] === 'BASIC' && ($validated['calculation'] !== 'fixed' || $validated['kind'] !== 'earning')) {
            throw new BusinessRuleException('BASIC must be a fixed earning.', 'BASIC_INVALID', 422);
        }

        $component ??= new PayComponent;
        $component->fill([...$validated, 'percent' => $validated['calculation'] === 'percent' ? $validated['percent'] : null])->save();

        return ApiResponse::data($this->component($component), $component->wasRecentlyCreated ? 201 : 200);
    }

    // ----- salaries --------------------------------------------------------

    /**
     * Working employees with their monthly gross and net (full month), or null when no salary is set.
     */
    public function salaries(Request $request): JsonResponse
    {
        Gate::authorize(P::ReportsView);
        $search = $request->input('filter.search');
        $components = PayComponent::query()->orderBy('position')->orderBy('id')->get();
        $employees = Employee::query()->with(['user', 'department', 'designation'])->whereIn('status', [EmployeeStatus::Active, EmployeeStatus::OnLeave])
            ->when($search, fn ($q, $term) => $q->where(fn ($w) => $w->where('employee_code', 'like', "%{$term}%")->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$term}%"))))
            ->orderBy('employee_code')->get();
        $salaries = EmployeeSalary::query()->whereIn('employee_id', $employees->pluck('id'))->get()->groupBy('employee_id');

        return ApiResponse::list($employees, function (Employee $e) use ($components, $salaries): array {
            $preview = $salaries->has($e->id) ? $this->preview($components, $salaries[$e->id]) : null;

            return [...$this->employee($e), 'gross' => $preview['gross'] ?? null, 'net' => $preview['net'] ?? null];
        });
    }

    /**
     * One employee's salary: every component with the assigned amount, and the full-month figures.
     */
    public function salary(Employee $employee): JsonResponse
    {
        Gate::authorize(P::ReportsView);
        $components = PayComponent::query()->orderBy('position')->orderBy('id')->get();
        $assigned = EmployeeSalary::query()->where('employee_id', $employee->id)->get();
        $byComponent = $assigned->keyBy('pay_component_id');

        return ApiResponse::data([
            'employee' => $this->employee($employee->load(['user', 'department', 'designation'])),
            'components' => $components->map(fn (PayComponent $c): array => [...$this->component($c), 'assigned' => $byComponent->has($c->id), 'amount' => $byComponent->has($c->id) && $byComponent[$c->id]->amount !== null ? (float) $byComponent[$c->id]->amount : null])->values(),
            'preview' => $assigned->isEmpty() ? null : $this->preview($components, $assigned),
        ]);
    }

    /**
     * Replace the salary: `components[] {id, amount?}`.
     */
    public function saveSalary(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize(P::SalariesManage);
        $validated = $request->validate(['components' => ['required', 'array', 'min:1'], 'components.*.id' => ['required', 'string', 'size:26'], 'components.*.amount' => ['nullable', 'numeric', 'min:0']]);
        $ids = PayComponent::query()->whereIn('ulid', array_column($validated['components'], 'id'))->pluck('id', 'ulid');
        $amounts = [];

        foreach ($validated['components'] as $row) {
            $amounts[(int) ($ids[$row['id']] ?? throw new BusinessRuleException('Unknown pay component.', 'COMPONENT_INVALID', 422))] = isset($row['amount']) ? (float) $row['amount'] : null;
        }

        $this->payroll->setSalary($employee, $amounts);

        return $this->salary($employee);
    }

    // ----- runs ------------------------------------------------------------

    public function runs(): JsonResponse
    {
        Gate::authorize(P::ReportsView);
        $runs = PayrollRun::query()->withCount('payslips')->withSum('payslips', 'net')->latest('month')->get();

        return ApiResponse::list($runs, fn (PayrollRun $r): array => [...$this->run($r), 'employees' => $r->payslips_count, 'net_total' => round((float) $r->getAttribute('payslips_sum_net'), 2)]);
    }

    public function storeRun(Request $request): JsonResponse
    {
        Gate::authorize(P::RunsProcess);
        $month = (string) $request->validate(['month' => ['required', 'date_format:Y-m']])['month'];

        return ApiResponse::data($this->run($this->payroll->createRun($month)->refresh()), 201);
    }

    /**
     * A run with every payslip (salary register) and totals.
     */
    public function showRun(Request $request, PayrollRun $run): JsonResponse
    {
        Gate::authorize(P::ReportsView);
        $user = $this->user($request);
        $payslips = $run->payslips()->with(['employee.user', 'employee.department', 'adjustments'])->get()->sortBy(fn (Payslip $p) => $p->employee->employee_code);

        return ApiResponse::data([
            ...$this->run($run->load(['computer', 'approver'])),
            'computed_by' => $run->computer?->name,
            'approved_by' => $run->approver?->name,
            'totals' => ['gross' => round((float) $payslips->sum('gross'), 2), 'deductions' => round((float) $payslips->sum('deductions'), 2), 'net' => round((float) $payslips->sum('net'), 2)],
            'by_department' => $payslips->groupBy(fn (Payslip $p): string => $p->employee->department->name ?? '—')->map(fn ($g): float => round((float) $g->sum('net'), 2))->sortKeys()->all(),
            'can' => [
                'edit' => $run->isEditable() && $user->can(P::RunsProcess),
                'approve' => $run->status === 'computed' && $user->can(P::RunsApprove) && $run->computed_by !== $user->id,
                'finalize' => $run->status === 'approved' && $user->can(P::RunsProcess),
            ],
            'payslips' => $payslips->map(fn (Payslip $p): array => $this->payslip($p))->values(),
        ]);
    }

    /**
     * Move the run on: `compute`, `approve`, `finalize` (`paid_on`).
     */
    public function transition(Request $request, PayrollRun $run, string $action): JsonResponse
    {
        $user = $this->user($request);

        if ($action === 'approve') {
            Gate::authorize(P::RunsApprove);
            $this->payroll->approve($run, $user);
        } else {
            Gate::authorize(P::RunsProcess);
            $action === 'compute'
                ? $this->payroll->compute($run, $user)
                : $this->payroll->finalize($run, (string) $request->validate(['paid_on' => ['required', 'date']])['paid_on'], $user);
        }

        return ApiResponse::data($this->run($run->refresh()));
    }

    public function destroyRun(PayrollRun $run): JsonResponse
    {
        Gate::authorize(P::RunsProcess);
        $this->payroll->delete($run);

        return ApiResponse::message('Payroll deleted.');
    }

    /**
     * Loss-of-pay days and one-off adjustments: `lop_days`, `adjustments[] {kind, label, amount}`.
     */
    public function saveInputs(Request $request, Payslip $payslip): JsonResponse
    {
        Gate::authorize(P::RunsProcess);
        $validated = $request->validate([
            'lop_days' => ['required', 'numeric', 'min:0', 'max:31', 'multiple_of:0.5'],
            'adjustments' => ['nullable', 'array', 'max:10'],
            'adjustments.*.kind' => ['required', Rule::in(['earning', 'deduction'])],
            'adjustments.*.label' => ['required', 'string', 'max:100'],
            'adjustments.*.amount' => ['required', 'numeric', 'min:1'],
        ]);
        $this->payroll->setInputs($payslip, (float) $validated['lop_days'], array_map(fn (array $a): array => ['kind' => $a['kind'], 'label' => $a['label'], 'amount' => (float) $a['amount']], $validated['adjustments'] ?? []));

        return ApiResponse::message('Saved. Calculate the payroll again.');
    }

    // ----- payslips --------------------------------------------------------

    /**
     * A payslip: payroll staff, or the employee once the run is finalised.
     */
    public function payslipShow(Request $request, Payslip $payslip): JsonResponse
    {
        $user = $this->user($request);
        $payslip->load(['employee.user', 'employee.department', 'employee.designation', 'run', 'adjustments']);
        $own = $payslip->employee->user_id === $user->id && $payslip->run->status === 'finalized';
        abort_unless($own || $user->can(P::ReportsView), 403);

        return ApiResponse::data([...$this->payslip($payslip), 'run' => $this->run($payslip->run), 'designation' => $payslip->employee->designation?->name]);
    }

    /**
     * The signed-in employee's finalised payslips, newest first.
     */
    public function mine(Request $request): JsonResponse
    {
        $employee = Employee::query()->where('user_id', $this->user($request)->id)->first() ?? throw new BusinessRuleException('You do not have a staff record in this institution.', 'NOT_AN_EMPLOYEE', 404);
        $payslips = Payslip::query()->with('run')->where('employee_id', $employee->id)->whereHas('run', fn ($q) => $q->where('status', 'finalized'))->get()->sortByDesc(fn (Payslip $p) => $p->run->month);

        return ApiResponse::list($payslips->values(), fn (Payslip $p): array => ['id' => $p->ulid, 'month' => $p->run->month, 'paid_on' => $p->run->paid_on?->toDateString(), 'gross' => (float) $p->gross, 'deductions' => (float) $p->deductions, 'net' => (float) $p->net]);
    }

    // ----- presenters ------------------------------------------------------

    /**
     * @param  iterable<PayComponent>  $components
     * @param  iterable<EmployeeSalary>  $assigned
     * @return array{lines: list<array{code: string|null, name: string, kind: string, amount: float}>, gross: float, deductions: float, net: float}
     */
    private function preview(iterable $components, iterable $assigned): array
    {
        $byComponent = collect($assigned)->keyBy('pay_component_id');

        return PayrollCalculator::compute(collect($components)->filter(fn (PayComponent $c): bool => $byComponent->has($c->id))->map(fn (PayComponent $c): array => [
            'code' => $c->code, 'name' => $c->name, 'kind' => $c->kind, 'calculation' => $c->calculation,
            'percent' => $c->percent === null ? null : (float) $c->percent, 'max_amount' => $c->max_amount === null ? null : (float) $c->max_amount,
            'affected_by_lop' => $c->affected_by_lop, 'amount' => $byComponent[$c->id]->amount === null ? null : (float) $byComponent[$c->id]->amount,
        ])->values()->all(), 30, 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function component(PayComponent $c): array
    {
        return [
            'id' => $c->ulid, 'code' => $c->code, 'name' => $c->name, 'kind' => $c->kind, 'calculation' => $c->calculation,
            'percent' => $c->percent === null ? null : (float) $c->percent, 'max_amount' => $c->max_amount === null ? null : (float) $c->max_amount,
            'affected_by_lop' => $c->affected_by_lop, 'position' => $c->position, 'status' => $c->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function employee(Employee $e): array
    {
        return ['id' => $e->ulid, 'code' => $e->employee_code, 'name' => $e->user->name, 'department' => $e->department?->name, 'designation' => $e->designation?->name, 'status' => $e->status->value];
    }

    /**
     * @return array<string, mixed>
     */
    private function run(PayrollRun $run): array
    {
        return [
            'id' => $run->ulid, 'month' => $run->month, 'status' => $run->status,
            'computed_at' => $run->computed_at?->toIso8601String(), 'approved_at' => $run->approved_at?->toIso8601String(),
            'finalized_at' => $run->finalized_at?->toIso8601String(), 'paid_on' => $run->paid_on?->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payslip(Payslip $p): array
    {
        return [
            'id' => $p->ulid,
            'employee' => ['code' => $p->employee->employee_code, 'name' => $p->employee->user->name, 'department' => $p->employee->department?->name],
            'days_in_month' => $p->days_in_month,
            'lop_days' => (float) $p->lop_days,
            'adjustments' => $p->adjustments->map(fn ($a): array => ['kind' => $a->kind, 'label' => $a->label, 'amount' => (float) $a->amount])->values(),
            'gross' => (float) $p->gross,
            'deductions' => (float) $p->deductions,
            'net' => (float) $p->net,
            'lines' => $p->lines ?? [],
        ];
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
