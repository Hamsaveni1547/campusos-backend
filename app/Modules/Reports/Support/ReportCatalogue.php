<?php

namespace App\Modules\Reports\Support;

use App\Models\User;
use App\Modules\Admissions\Models\Application;
use App\Modules\Exams\Models\ExamCycle;
use App\Modules\Exams\Models\ExamResult;
use App\Modules\Feedback\Models\Complaint;
use App\Modules\Finance\Models\FeeDemand;
use App\Modules\Finance\Models\FeePayment;
use App\Modules\HR\Models\Employee;
use App\Modules\Institution\Models\Programme;
use App\Modules\Leave\Models\LeaveApplication;
use App\Modules\Library\Models\LibraryLoan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Standard reports. Each has a definition (who may run it, filters, columns) and a method that returns rows.
 * Access reuses each module's own "reports" permission.
 */
class ReportCatalogue
{
    /**
     * @return array<string, array{label: string, module: string, description: string, permission: string, filters: list<string>, columns: array<string, array{0: string, 1: string}>}>
     */
    public function definitions(): array
    {
        return [
            'fee-defaulters' => ['label' => 'Fee defaulters', 'module' => 'Finance', 'description' => 'Students with fees past the due date.', 'permission' => 'finance.reports.view', 'filters' => ['programme'],
                'columns' => ['register_no' => ['Register no.', 'text'], 'name' => ['Student', 'text'], 'programme' => ['Programme', 'text'], 'demands' => ['Demands', 'number'], 'oldest_due' => ['Oldest due', 'date'], 'overdue' => ['Overdue', 'money']]],
            'fee-collection' => ['label' => 'Fee collection', 'module' => 'Finance', 'description' => 'Receipts between two dates.', 'permission' => 'finance.reports.view', 'filters' => ['from', 'to'],
                'columns' => ['receipt_no' => ['Receipt', 'text'], 'paid_on' => ['Date', 'date'], 'register_no' => ['Register no.', 'text'], 'name' => ['Student', 'text'], 'mode' => ['Mode', 'text'], 'amount' => ['Amount', 'money']]],
            'results-summary' => ['label' => 'Results summary', 'module' => 'Exams', 'description' => 'Pass percentage and average SGPA by programme for an exam cycle.', 'permission' => 'exams.reports.view', 'filters' => ['exam_cycle'],
                'columns' => ['programme' => ['Programme', 'text'], 'students' => ['Students', 'number'], 'passed' => ['All clear', 'number'], 'pass_percentage' => ['Pass %', 'number'], 'average_sgpa' => ['Average SGPA', 'number']]],
            'admissions-summary' => ['label' => 'Admissions summary', 'module' => 'Admissions', 'description' => 'Applications by programme and status.', 'permission' => 'admissions.applications.view', 'filters' => [],
                'columns' => ['programme' => ['Programme', 'text'], 'submitted' => ['Applied', 'number'], 'offered' => ['Offered', 'number'], 'admitted' => ['Admitted', 'number'], 'rejected' => ['Rejected / withdrawn', 'number']]],
            'staff-headcount' => ['label' => 'Staff headcount', 'module' => 'HR', 'description' => 'Active staff by department and type.', 'permission' => 'hr.employees.view', 'filters' => [],
                'columns' => ['department' => ['Department', 'text'], 'teaching' => ['Teaching', 'number'], 'non_teaching' => ['Non-teaching', 'number'], 'total' => ['Total', 'number']]],
            'leave-register' => ['label' => 'Leave register', 'module' => 'Leave', 'description' => 'Approved leave between two dates.', 'permission' => 'leave.reports.view', 'filters' => ['from', 'to'],
                'columns' => ['employee' => ['Employee', 'text'], 'department' => ['Department', 'text'], 'type' => ['Leave type', 'text'], 'from_date' => ['From', 'date'], 'to_date' => ['To', 'date'], 'days' => ['Days', 'number']]],
            'library-overdue' => ['label' => 'Library overdue', 'module' => 'Library', 'description' => 'Books not returned by the due date.', 'permission' => 'library.reports.view', 'filters' => [],
                'columns' => ['member' => ['Member', 'text'], 'member_type' => ['Type', 'text'], 'book' => ['Book', 'text'], 'due_on' => ['Due', 'date'], 'days_overdue' => ['Days overdue', 'number']]],
            'complaints-summary' => ['label' => 'Complaints summary', 'module' => 'Feedback', 'description' => 'Complaints by category with resolution and rating.', 'permission' => 'feedback.complaints.handle', 'filters' => [],
                'columns' => ['category' => ['Category', 'text'], 'total' => ['Total', 'number'], 'open' => ['Open', 'number'], 'overdue' => ['Past deadline', 'number'], 'resolved' => ['Resolved', 'number'], 'average_rating' => ['Average rating', 'number']]],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function availableTo(User $user): array
    {
        return array_filter($this->definitions(), fn (array $d): bool => $user->can($d['permission']));
    }

    /**
     * @param  array<string, string|null>  $filters
     * @return list<array<string, mixed>>
     */
    public function run(string $key, array $filters): array
    {
        $method = str($key)->camel()->toString();

        return $this->{$method}($filters);
    }

    /**
     * @param  array<string, string|null>  $f
     * @return list<array<string, mixed>>
     */
    private function feeDefaulters(array $f): array
    {
        $programmeId = isset($f['programme']) ? Programme::query()->where('ulid', $f['programme'])->value('id') : null;

        return FeeDemand::query()->with('student.user', 'student.programme')->whereIn('status', ['open', 'partly_paid'])->whereDate('due_on', '<', today())
            ->when($programmeId, fn ($q) => $q->whereHas('student', fn ($q) => $q->where('programme_id', $programmeId)))
            ->get()->groupBy('student_id')
            ->map(fn (Collection $demands): array => [
                'register_no' => $demands->first()->student->register_no,
                'name' => $demands->first()->student->user->name,
                'programme' => $demands->first()->student->programme->name,
                'demands' => $demands->count(),
                'oldest_due' => $demands->min('due_on')->toDateString(),
                'overdue' => round($demands->sum(fn (FeeDemand $d): float => $d->balance()), 2),
            ])->sortByDesc('overdue')->values()->all();
    }

    /**
     * @param  array<string, string|null>  $f
     * @return list<array<string, mixed>>
     */
    private function feeCollection(array $f): array
    {
        [$from, $to] = $this->range($f);

        return FeePayment::query()->with('student.user')->where('status', 'valid')->whereBetween('paid_on', [$from, $to])->orderBy('paid_on')->orderBy('id')->get()
            ->map(fn (FeePayment $p): array => ['receipt_no' => $p->receipt_no, 'paid_on' => $p->paid_on->toDateString(), 'register_no' => $p->student->register_no, 'name' => $p->student->user->name, 'mode' => strtoupper($p->mode), 'amount' => (float) $p->amount])->values()->all();
    }

    /**
     * @param  array<string, string|null>  $f
     * @return list<array<string, mixed>>
     */
    private function resultsSummary(array $f): array
    {
        $cycle = isset($f['exam_cycle']) ? ExamCycle::query()->where('ulid', $f['exam_cycle'])->first() : ExamCycle::query()->whereHas('results')->latest('id')->first();

        if (! $cycle) {
            return [];
        }

        return ExamResult::query()->with('student.programme')->where('exam_cycle_id', $cycle->id)->get()->groupBy('student.programme.name')
            ->map(fn (Collection $results, string $programme): array => [
                'programme' => $programme,
                'students' => $results->count(),
                'passed' => $passed = $results->where('backlogs', 0)->count(),
                'pass_percentage' => round($passed * 100 / max(1, $results->count()), 1),
                'average_sgpa' => round((float) $results->whereNotNull('sgpa')->avg(fn (ExamResult $r): float => (float) $r->sgpa), 2),
            ])->sortBy('programme')->values()->all();
    }

    /**
     * @param  array<string, string|null>  $f
     * @return list<array<string, mixed>>
     */
    private function admissionsSummary(array $f): array
    {
        return Application::query()->with('seat.programme')->whereNotNull('submitted_at')->get()->groupBy('seat.programme.name')
            ->map(fn (Collection $apps, string $programme): array => [
                'programme' => $programme,
                'submitted' => $apps->count(),
                'offered' => $apps->filter(fn (Application $a) => in_array($a->status->value, ['offered', 'offer_accepted', 'admitted'], true))->count(),
                'admitted' => $apps->filter(fn (Application $a) => $a->status->value === 'admitted')->count(),
                'rejected' => $apps->filter(fn (Application $a) => in_array($a->status->value, ['rejected', 'withdrawn', 'ineligible', 'expired'], true))->count(),
            ])->sortBy('programme')->values()->all();
    }

    /**
     * @param  array<string, string|null>  $f
     * @return list<array<string, mixed>>
     */
    private function staffHeadcount(array $f): array
    {
        return Employee::query()->with('department')->whereIn('status', ['active', 'on_leave'])->get()->groupBy(fn (Employee $e): string => $e->department->name)
            ->map(fn (Collection $staff, string $department): array => [
                'department' => $department,
                'teaching' => $teaching = $staff->filter(fn (Employee $e) => $e->staff_type->value === 'teaching')->count(),
                'non_teaching' => $staff->count() - $teaching,
                'total' => $staff->count(),
            ])->sortBy('department')->values()->all();
    }

    /**
     * @param  array<string, string|null>  $f
     * @return list<array<string, mixed>>
     */
    private function leaveRegister(array $f): array
    {
        [$from, $to] = $this->range($f);

        return LeaveApplication::query()->with('employee.user', 'employee.department', 'type')->where('status', 'approved')
            ->whereDate('from_date', '<=', $to)->whereDate('to_date', '>=', $from)->orderBy('from_date')->get()
            ->map(fn (LeaveApplication $l): array => ['employee' => $l->employee->user->name, 'department' => $l->employee->department?->name, 'type' => $l->type->name, 'from_date' => $l->from_date->toDateString(), 'to_date' => $l->to_date->toDateString(), 'days' => (float) $l->days])->values()->all();
    }

    /**
     * @param  array<string, string|null>  $f
     * @return list<array<string, mixed>>
     */
    private function libraryOverdue(array $f): array
    {
        return LibraryLoan::query()->with('member', 'copy.book')->whereNull('returned_on')->whereDate('due_on', '<', today())->orderBy('due_on')->get()
            ->map(fn (LibraryLoan $l): array => ['member' => $l->member->name, 'member_type' => ucfirst($l->member_type), 'book' => $l->copy->book->title, 'due_on' => $l->due_on->toDateString(), 'days_overdue' => (int) $l->due_on->diffInDays(today())])->values()->all();
    }

    /**
     * @param  array<string, string|null>  $f
     * @return list<array<string, mixed>>
     */
    private function complaintsSummary(array $f): array
    {
        return Complaint::query()->with('category')->get()->groupBy('category.name')
            ->map(fn (Collection $items, string $category): array => [
                'category' => $category,
                'total' => $items->count(),
                'open' => $items->whereIn('status', ['open', 'in_progress'])->count(),
                'overdue' => $items->filter(fn (Complaint $c) => $c->isOverdue())->count(),
                'resolved' => $items->whereIn('status', ['resolved', 'closed'])->count(),
                'average_rating' => $items->whereNotNull('rating')->isEmpty() ? null : round((float) $items->whereNotNull('rating')->avg('rating'), 1),
            ])->sortBy('category')->values()->all();
    }

    /**
     * @param  array<string, string|null>  $f
     * @return array{0: string, 1: string}
     */
    private function range(array $f): array
    {
        $from = isset($f['from']) ? Carbon::parse($f['from']) : today()->startOfMonth();
        $to = isset($f['to']) ? Carbon::parse($f['to']) : today();

        return [$from->toDateString(), $to->toDateString()];
    }
}
