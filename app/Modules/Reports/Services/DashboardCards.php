<?php

namespace App\Modules\Reports\Services;

use App\Models\User;
use App\Modules\Admissions\Models\Application;
use App\Modules\Assets\Models\MaintenanceRequest;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Communication\Models\AnnouncementRecipient;
use App\Modules\Exams\Models\ExamResult;
use App\Modules\Feedback\Models\Complaint;
use App\Modules\Finance\Models\FeeDemand;
use App\Modules\Finance\Models\FeePayment;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\HR\Models\Employee;
use App\Modules\Institution\Models\Term;
use App\Modules\Leave\Models\LeaveApplication;
use App\Modules\Library\Models\LibraryLoan;
use App\Modules\Mentoring\Models\MentoringMeeting;
use App\Modules\Students\Models\Student;

/**
 * Figures for a person's home page (and the assistant): personal cards from their own records,
 * management cards from their permissions.
 */
class DashboardCards
{
    /** @var list<array<string, mixed>> */
    private array $cards = [];

    public function __construct(private AttendanceService $attendance, private FinanceService $finance) {}

    /**
     * Card: `key`, `label`, `value`, `format` number|money|percent|text, `tone` neutral|success|warning|danger, `hint`, `link` (a screen name).
     *
     * @return list<array<string, mixed>>
     */
    public function for(User $user): array
    {
        $this->cards = [];
        $this->personal($user);
        $this->management($user);

        return $this->cards;
    }

    private function personal(User $user): void
    {
        $unread = AnnouncementRecipient::query()->where('user_id', $user->id)->whereNull('read_at')
            ->whereHas('announcement', fn ($q) => $q->where('status', 'published')->where(fn ($q) => $q->whereNull('expires_on')->orWhere('expires_on', '>=', today())))->count();
        $this->card('announcements', 'Unread announcements', $unread, tone: $unread > 0 ? 'warning' : 'neutral', link: 'announcements');

        $student = Student::query()->where('user_id', $user->id)->first();

        if ($student) {
            $termId = Term::query()->where('is_current', true)->value('id');
            $classes = $termId ? collect($this->attendance->studentSummary($student, (int) $termId)) : collect();
            $held = $classes->sum('held');
            $percentage = $held > 0 ? round($classes->sum('attended') * 100 / $held, 1) : null;
            $short = $classes->where('standing', 'shortage')->count();
            $this->card('attendance', 'Attendance this term', $percentage, 'percent', $short > 0 ? 'danger' : 'success', $short > 0 ? "Short in {$short} course(s)" : null, 'my-attendance');

            $fees = $this->finance->totals($student);
            $this->card('fees', 'Fee balance', $fees['balance'], 'money', $fees['overdue'] > 0 ? 'danger' : 'success', $fees['overdue'] > 0 ? 'Overdue: '.number_format($fees['overdue'], 0) : null, 'my-fees');

            $result = ExamResult::query()->where('student_id', $student->id)->whereHas('cycle', fn ($q) => $q->where('status', 'published'))->latest('id')->first();
            if ($result) {
                $this->card('cgpa', 'CGPA', $result->cgpa === null ? null : (float) $result->cgpa, 'text', $result->backlogs > 0 ? 'warning' : 'success', $result->backlogs > 0 ? "{$result->backlogs} backlog(s)" : null, 'my-results');
            }
        }

        $employee = Employee::query()->where('user_id', $user->id)->first();

        if ($employee && $employee->staff_type->value === 'teaching') {
            $pending = count($this->attendance->pending($employee));
            $this->card('attendance-pending', 'Classes to mark', $pending, tone: $pending > 0 ? 'warning' : 'success', hint: 'Last 7 days', link: 'attendance');
            $requests = MentoringMeeting::query()->where('employee_id', $employee->id)->where('status', 'requested')->count();
            if ($requests > 0) {
                $this->card('meeting-requests', 'Meeting requests', $requests, tone: 'warning', link: 'my-mentees');
            }
        }

        $loans = LibraryLoan::query()->where('user_id', $user->id)->whereNull('returned_on')->get();
        if ($loans->isNotEmpty()) {
            $overdue = $loans->filter(fn (LibraryLoan $l) => $l->isOverdue())->count();
            $this->card('library', 'Books with me', $loans->count(), tone: $overdue > 0 ? 'danger' : 'neutral', hint: $overdue > 0 ? "{$overdue} overdue" : null, link: 'my-library');
        }
    }

    private function management(User $user): void
    {
        if ($user->can('students.records.view')) {
            $this->card('students', 'Active students', Student::query()->where('status', 'active')->count(), link: 'students');
        }

        if ($user->can('hr.employees.view')) {
            $this->card('staff', 'Active staff', Employee::query()->where('status', 'active')->count(), link: 'employees');
        }

        if ($user->can('finance.reports.view')) {
            $this->card('collected', 'Collected this month', round((float) FeePayment::query()->where('status', 'valid')->whereBetween('paid_on', [today()->startOfMonth(), today()])->sum('amount'), 2), 'money', 'success', link: 'reports');
            $overdue = FeeDemand::query()->whereIn('status', ['open', 'partly_paid'])->whereDate('due_on', '<', today())->get();
            $this->card('fees-overdue', 'Fees overdue', round($overdue->sum(fn (FeeDemand $d): float => $d->balance()), 2), 'money', $overdue->isEmpty() ? 'success' : 'danger', $overdue->pluck('student_id')->unique()->count().' students', 'reports');
        }

        if ($user->can('leave.approve')) {
            $pending = LeaveApplication::query()->where('status', 'pending')->count();
            $this->card('leave-pending', 'Leave to approve', $pending, tone: $pending > 0 ? 'warning' : 'neutral', link: 'leave-approvals');
        }

        if ($user->can('admissions.applications.view')) {
            $waiting = Application::query()->whereIn('status', ['submitted', 'verified', 'eligible'])->count();
            $this->card('applications', 'Applications in review', $waiting, tone: $waiting > 0 ? 'warning' : 'neutral', link: 'applications');
        }

        if ($user->can('feedback.complaints.handle')) {
            $open = Complaint::query()->whereIn('status', ['open', 'in_progress'])->get();
            $late = $open->filter(fn (Complaint $c) => $c->isOverdue())->count();
            $this->card('complaints', 'Open complaints', $open->count(), tone: $late > 0 ? 'danger' : 'neutral', hint: $late > 0 ? "{$late} past deadline" : null, link: 'complaints');
        }

        if ($user->can('library.reports.view')) {
            $this->card('library-overdue', 'Overdue books', LibraryLoan::query()->whereNull('returned_on')->whereDate('due_on', '<', today())->count(), tone: 'warning', link: 'library-desk');
        }

        if ($user->can('assets.reports.view')) {
            $this->card('maintenance', 'Open repair requests', MaintenanceRequest::query()->whereIn('status', ['open', 'in_progress'])->count(), link: 'maintenance');
        }
    }

    private function card(string $key, string $label, int|float|string|null $value, string $format = 'number', string $tone = 'neutral', ?string $hint = null, ?string $link = null): void
    {
        $this->cards[] = compact('key', 'label', 'value', 'format', 'tone', 'hint', 'link');
    }
}
