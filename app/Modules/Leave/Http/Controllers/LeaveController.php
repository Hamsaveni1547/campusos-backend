<?php

namespace App\Modules\Leave\Http\Controllers;

use App\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Authorization\LeavePermissions as P;
use App\Modules\Leave\Models\LeaveApplication;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveService;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Http\ApiResponse;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * @tags Leave
 */
class LeaveController extends Controller
{
    public function __construct(private LeaveService $leave) {}

    // ----- types and balances ----------------------------------------------

    public function types(): JsonResponse
    {
        return ApiResponse::list(LeaveType::query()->orderBy('name')->get(), fn (LeaveType $t): array => $this->type($t));
    }

    public function storeType(Request $request): JsonResponse
    {
        return $this->saveType($request, null);
    }

    public function updateType(Request $request, LeaveType $type): JsonResponse
    {
        return $this->saveType($request, $type);
    }

    private function saveType(Request $request, ?LeaveType $type): JsonResponse
    {
        Gate::authorize(P::Manage);
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:10', Rule::unique('leave_types', 'code')->where('institution_id', app(CurrentInstitution::class)->id())->ignore($type?->id)],
            'name' => ['required', 'string', 'max:100'],
            'days_per_year' => ['required', 'numeric', 'min:0', 'max:366'],
            'is_paid' => ['required', 'boolean'],
            'allow_half_day' => ['required', 'boolean'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        $type ??= new LeaveType;
        $type->fill([...$validated, 'code' => strtoupper($validated['code'])])->save();

        return ApiResponse::data($this->type($type), $type->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Give every working employee their yearly days (`year`, default this year).
     */
    public function allot(Request $request): JsonResponse
    {
        Gate::authorize(P::Manage);
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'min:2000', 'max:2100']])['year'] ?? now()->year);
        $count = $this->leave->allot($year);

        return ApiResponse::data(['created' => $count, 'message' => $count === 0 ? 'Everyone already has balances for '.$year.'.' : "{$count} balances created for {$year}."]);
    }

    /**
     * An employee's balances for a year (staff with reports or manage permission).
     */
    public function employeeBalances(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize(P::ReportsView);
        $year = (int) ($request->validate(['year' => ['nullable', 'integer']])['year'] ?? now()->year);

        return ApiResponse::list(collect($this->leave->balances($employee, $year)), fn (array $b): array => $this->balance($b));
    }

    /**
     * Change the days allotted: `leave_type_id`, `year`, `allotted`.
     */
    public function adjust(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize(P::Manage);
        $validated = $request->validate(['leave_type_id' => ['required', 'string', 'size:26'], 'year' => ['required', 'integer'], 'allotted' => ['required', 'numeric', 'min:0', 'max:366']]);
        $type = LeaveType::query()->where('ulid', $validated['leave_type_id'])->firstOrFail();
        LeaveBalance::query()->updateOrCreate(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $validated['year']], ['allotted' => $validated['allotted']]);

        return ApiResponse::message('Balance updated.');
    }

    // ----- applications ----------------------------------------------------

    /**
     * Applications. Filters: `filter[status]`, `filter[from]`, `filter[to]` (on leave between), `filter[department]`.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($user->canAny([P::Approve, P::ReportsView]), 403);
        $f = (array) $request->input('filter', []);

        $applications = LeaveApplication::query()->with(['employee.user', 'employee.department', 'type', 'decider'])
            ->when($f['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($f['from'] ?? null, fn ($q, $from) => $q->whereDate('to_date', '>=', $from))
            ->when($f['to'] ?? null, fn ($q, $to) => $q->whereDate('from_date', '<=', $to))
            ->when($f['department'] ?? null, fn ($q, $ulid) => $q->whereHas('employee.department', fn ($d) => $d->where('ulid', $ulid)))
            ->orderByDesc('from_date')->paginate(25);

        return ApiResponse::paginated($applications, fn (LeaveApplication $a): array => [...$this->application($a), 'can_decide' => $a->status === 'pending' && $user->can(P::Approve) && $a->employee->user_id !== $user->id]);
    }

    public function decide(Request $request, LeaveApplication $application): JsonResponse
    {
        Gate::authorize(P::Approve);
        $validated = $request->validate(['approve' => ['required', 'boolean'], 'remarks' => ['nullable', 'string', 'max:500']]);
        $this->leave->decide($application, (bool) $validated['approve'], $validated['remarks'] ?? null, $this->user($request));

        return ApiResponse::message($validated['approve'] ? 'Leave approved.' : 'Leave rejected.');
    }

    // ----- self service ----------------------------------------------------

    /**
     * The signed-in employee's balances this year and applications.
     */
    public function mine(Request $request): JsonResponse
    {
        $employee = $this->employee($request);

        return ApiResponse::data([
            'year' => now()->year,
            'balances' => array_map(fn (array $b): array => $this->balance($b), $this->leave->balances($employee, now()->year)),
            'applications' => LeaveApplication::query()->with(['type', 'decider', 'employee.user'])->where('employee_id', $employee->id)->orderByDesc('from_date')->limit(50)->get()
                ->map(fn (LeaveApplication $a): array => [...$this->application($a), 'can_cancel' => $a->status === 'pending' || ($a->status === 'approved' && $a->from_date->gt(today()))])->values(),
        ]);
    }

    /**
     * Apply: `leave_type_id`, `from_date`, `to_date`, `half_day`, `reason`.
     */
    public function apply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'leave_type_id' => ['required', 'string', 'size:26'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date'],
            'half_day' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $type = LeaveType::query()->where('ulid', $validated['leave_type_id'])->where('status', 'active')->firstOrFail();
        $application = $this->leave->apply($this->employee($request), $type, Carbon::parse($validated['from_date']), Carbon::parse($validated['to_date']), (bool) ($validated['half_day'] ?? false), $validated['reason']);

        return ApiResponse::data($this->application($application->load(['type', 'employee.user'])), 201);
    }

    public function cancel(Request $request, LeaveApplication $application): JsonResponse
    {
        abort_unless($application->employee->user_id === $this->user($request)->id, 403);
        $this->leave->cancel($application);

        return ApiResponse::message('Leave cancelled.');
    }

    // ----- presenters ------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function type(LeaveType $t): array
    {
        return ['id' => $t->ulid, 'code' => $t->code, 'name' => $t->name, 'days_per_year' => (float) $t->days_per_year, 'is_paid' => $t->is_paid, 'allow_half_day' => $t->allow_half_day, 'status' => $t->status];
    }

    /**
     * @param  array{type: LeaveType, allotted: float, used: float, pending: float, available: float|null}  $b
     * @return array<string, mixed>
     */
    private function balance(array $b): array
    {
        return ['type' => $this->type($b['type']), 'allotted' => $b['allotted'], 'used' => $b['used'], 'pending' => $b['pending'], 'available' => $b['available']];
    }

    /**
     * @return array<string, mixed>
     */
    private function application(LeaveApplication $a): array
    {
        return [
            'id' => $a->ulid,
            'employee' => ['id' => $a->employee->ulid, 'name' => $a->employee->user->name, 'code' => $a->employee->employee_code, 'department' => $a->employee->relationLoaded('department') ? $a->employee->department?->name : null],
            'type' => ['code' => $a->type->code, 'name' => $a->type->name, 'is_paid' => $a->type->is_paid],
            'from_date' => $a->from_date->toDateString(),
            'to_date' => $a->to_date->toDateString(),
            'half_day' => $a->half_day,
            'days' => (float) $a->days,
            'reason' => $a->reason,
            'status' => $a->status,
            'remarks' => $a->remarks,
            'decided_by' => $a->decider?->name,
            'applied_at' => $a->created_at?->toIso8601String(),
        ];
    }

    private function employee(Request $request): Employee
    {
        return Employee::query()->where('user_id', $this->user($request)->id)->first() ?? throw new BusinessRuleException('You do not have a staff record in this institution.', 'NOT_AN_EMPLOYEE', 404);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
