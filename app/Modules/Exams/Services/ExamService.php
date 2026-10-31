<?php

namespace App\Modules\Exams\Services;

use App\Models\User;
use App\Modules\Academics\Models\CourseRegistration;
use App\Modules\Academics\Models\OfferingSection;
use App\Modules\Assessment\Models\InternalMark;
use App\Modules\Assessment\Models\InternalMarkSheet;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Exams\Models\ExamCycle;
use App\Modules\Exams\Models\ExamRegistration;
use App\Modules\Exams\Models\ExamResult;
use App\Modules\Exams\Models\ExamSchedule;
use App\Modules\Exams\Models\RevaluationRequest;
use App\Modules\Exams\Support\ResultProcessor;
use App\Modules\Students\Models\Student;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Settings\SettingsRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Exam cycle: registration, eligibility, timetable, script codes, marks, results and revaluation.
 */
class ExamService
{
    public function __construct(private AttendanceService $attendance, private SettingsRepository $settings) {}

    // ----- registration ----------------------------------------------------

    /**
     * Opens registration; a regular cycle registers every student for the courses of their term registration.
     */
    public function openRegistration(ExamCycle $cycle): void
    {
        $this->assertStatus($cycle, 'draft');

        DB::transaction(function () use ($cycle): void {
            if ($cycle->type === 'regular') {
                CourseRegistration::query()
                    ->with('offering')
                    ->join('term_registrations', 'term_registrations.id', '=', 'course_registrations.term_registration_id')
                    ->where('term_registrations.term_id', $cycle->term_id)
                    ->where('term_registrations.status', '!=', 'rejected')
                    ->select('course_registrations.*', 'term_registrations.student_id')
                    ->get()
                    ->each(function (CourseRegistration $row) use ($cycle): void {
                        ExamRegistration::query()->firstOrCreate(
                            ['exam_cycle_id' => $cycle->id, 'student_id' => $row->getAttribute('student_id'), 'course_id' => $row->offering->course_id],
                            ['course_offering_id' => $row->course_offering_id, 'kind' => 'regular'],
                        );
                    });
            }

            $cycle->forceFill(['status' => 'registration_open'])->save();
        });
    }

    /**
     * Courses the student failed in a published cycle and has not cleared since.
     *
     * @return Collection<int, ExamRegistration>
     */
    public function backlogs(Student $student): Collection
    {
        return $this->latestAttempts($student, null)->filter(fn (ExamRegistration $row): bool => $row->passed === false)->values();
    }

    /**
     * @param  list<int>  $courseIds
     */
    public function registerBacklogs(ExamCycle $cycle, Student $student, array $courseIds): void
    {
        $this->assertStatus($cycle, 'registration_open');

        if ($cycle->registration_ends_on !== null && now()->startOfDay()->gt($cycle->registration_ends_on)) {
            throw new BusinessRuleException('Registration for this exam has closed.', 'REGISTRATION_CLOSED', 409);
        }

        $backlogs = $this->backlogs($student)->pluck('course_id')->all();

        if (array_diff($courseIds, $backlogs) !== []) {
            throw new BusinessRuleException('You can only register for courses you have not cleared.', 'NOT_A_BACKLOG', 422);
        }

        foreach ($courseIds as $courseId) {
            ExamRegistration::query()->firstOrCreate(['exam_cycle_id' => $cycle->id, 'student_id' => $student->id, 'course_id' => $courseId], ['kind' => 'backlog']);
        }
    }

    /**
     * Closes registration, decides eligibility and gives eligible scripts their codes.
     */
    public function closeRegistration(ExamCycle $cycle): void
    {
        $this->assertStatus($cycle, 'registration_open');

        DB::transaction(function () use ($cycle): void {
            $cycle->forceFill(['status' => 'registration_closed'])->save();
            $this->computeEligibility($cycle);
        });
    }

    /**
     * Regular courses need attendance at or above the threshold (or condoned) and verified internal marks.
     * Backlog courses carry their earlier internal marks. Overridden rows stay eligible.
     */
    public function computeEligibility(ExamCycle $cycle): void
    {
        if (! $cycle->isEvaluating()) {
            throw new BusinessRuleException('Eligibility can change only while exams are being evaluated.', 'INVALID_STATUS', 409);
        }

        $rows = $cycle->registrations()->with('student')->get();

        foreach ($rows->groupBy('student_id') as $studentRows) {
            /** @var ExamRegistration $first */
            $first = $studentRows->first();
            $standing = collect($this->attendance->studentSummary($first->student, $cycle->term_id))->pluck('standing', 'class_id');

            foreach ($studentRows as $row) {
                $reasons = [];

                if ($row->kind === 'regular') {
                    $class = OfferingSection::query()->where('course_offering_id', $row->course_offering_id)->where('section_id', $row->student->section_id)->first();
                    $sheet = $class !== null ? InternalMarkSheet::query()->where('offering_section_id', $class->id)->first() : null;
                    $mark = $sheet !== null ? InternalMark::query()->where('internal_mark_sheet_id', $sheet->id)->where('student_id', $row->student_id)->first() : null;

                    if (in_array($standing->get($class?->ulid), ['shortage', 'condonable'], true)) {
                        $reasons[] = 'Attendance below the minimum';
                    }

                    if ($sheet === null || ! in_array($sheet->status, ['verified', 'locked'], true) || $mark === null) {
                        $reasons[] = 'Internal marks not verified';
                    }

                    $row->forceFill(['internal_marks' => $mark?->final, 'internal_out_of' => $sheet?->rules['total'] ?? null]);
                } else {
                    $previous = $this->latestAttempts($row->student, $cycle->id)->firstWhere('course_id', $row->course_id);
                    $row->forceFill(['internal_marks' => $previous?->internal_marks, 'internal_out_of' => $previous?->internal_out_of]);
                }

                $row->forceFill(['eligibility' => $reasons === [] || $row->overridden_by !== null ? 'eligible' : 'not_eligible', 'eligibility_reasons' => $reasons ?: null])->save();
            }
        }

        $this->assignScriptCodes($cycle);
    }

    public function override(ExamRegistration $registration, string $reason, User $user): void
    {
        if (! $registration->cycle->isEvaluating()) {
            throw new BusinessRuleException('Eligibility can change only while exams are being evaluated.', 'INVALID_STATUS', 409);
        }

        $registration->forceFill(['eligibility' => 'eligible', 'override_reason' => $reason, 'overridden_by' => $user->id])->save();
        $this->assignScriptCodes($registration->cycle);
    }

    // ----- timetable -------------------------------------------------------

    /**
     * @param  array{course_id: int, exam_date: string, starts_at: string, ends_at: string, max_marks: float}  $data
     */
    public function saveSchedule(ExamCycle $cycle, array $data, ?ExamSchedule $schedule = null): ExamSchedule
    {
        if (in_array($cycle->status, ['results_verified', 'published'], true)) {
            throw new BusinessRuleException('Results are already verified.', 'INVALID_STATUS', 409);
        }

        if ($data['ends_at'] <= $data['starts_at']) {
            throw new BusinessRuleException('The exam must end after it starts.', 'TIME_INVALID', 422);
        }

        $students = $cycle->registrations()->where('course_id', $data['course_id'])->pluck('student_id');
        $clashes = $cycle->registrations()
            ->whereIn('student_id', $students)
            ->where('course_id', '!=', $data['course_id'])
            ->whereIn('course_id', $cycle->schedules()
                ->when($schedule, fn ($query) => $query->whereKeyNot($schedule?->id))
                ->whereDate('exam_date', $data['exam_date'])
                ->where('starts_at', '<', $data['ends_at'])
                ->where('ends_at', '>', $data['starts_at'])
                ->select('course_id'))
            ->distinct()
            ->count('student_id');

        if ($clashes > 0) {
            throw new BusinessRuleException($clashes.' student(s) have another exam at that time.', 'SCHEDULE_CLASH', 409, ['students' => $clashes]);
        }

        $schedule ??= new ExamSchedule(['exam_cycle_id' => $cycle->id]);
        $schedule->fill($data)->save();

        return $schedule;
    }

    // ----- marks -----------------------------------------------------------

    /**
     * @param  array<string, array{attendance: string, marks: float|null}>  $entries  script code => entry
     */
    public function enterMarks(ExamSchedule $schedule, array $entries, User $user): void
    {
        if (! $schedule->cycle->isEvaluating()) {
            throw new BusinessRuleException('Marks can be entered only while exams are being evaluated.', 'INVALID_STATUS', 409);
        }

        $rows = ExamRegistration::query()->where('exam_cycle_id', $schedule->exam_cycle_id)->where('course_id', $schedule->course_id)
            ->where('eligibility', 'eligible')->whereIn('script_code', array_keys($entries))->get()->keyBy('script_code');

        foreach ($entries as $code => $entry) {
            if (! $rows->has($code)) {
                throw new BusinessRuleException('Unknown script code '.$code.'.', 'SCRIPT_INVALID', 422);
            }

            if ($entry['attendance'] === 'present' && ($entry['marks'] === null || $entry['marks'] < 0 || $entry['marks'] > (float) $schedule->max_marks)) {
                throw new BusinessRuleException('Marks must be between 0 and '.((float) $schedule->max_marks).'.', 'MARKS_OUT_OF_RANGE', 422);
            }
        }

        DB::transaction(function () use ($rows, $entries, $user): void {
            foreach ($entries as $code => $entry) {
                $rows[$code]->forceFill([
                    'attendance' => $entry['attendance'],
                    'external_marks' => $entry['attendance'] === 'present' ? $entry['marks'] : null,
                    'marks_entered_by' => $user->id,
                ])->save();
            }
        });
    }

    // ----- results ---------------------------------------------------------

    public function process(ExamCycle $cycle, User $user): void
    {
        if (! $cycle->isEvaluating()) {
            throw new BusinessRuleException('Close registration before processing results.', 'INVALID_STATUS', 409);
        }

        $rows = $cycle->registrations()->with(['course', 'student'])->get();
        $max = $cycle->schedules()->pluck('max_marks', 'course_id');
        $missing = $rows->filter(fn (ExamRegistration $r): bool => $r->eligibility === 'eligible' && (! $max->has($r->course_id) || $r->attendance === null))->count();

        if ($missing > 0) {
            throw new BusinessRuleException($missing.' eligible script(s) have no marks or no exam in the timetable.', 'MARKS_MISSING', 422, ['scripts' => $missing]);
        }

        DB::transaction(function () use ($cycle, $rows, $max, $user): void {
            foreach ($rows as $row) {
                $this->grade($row, (float) ($max[$row->course_id] ?? 0));
            }

            foreach ($rows->groupBy('student_id') as $studentRows) {
                $this->summarise($cycle, $studentRows->first()->student);
            }

            $cycle->forceFill(['status' => 'results_processed', 'processed_by' => $user->id, 'processed_at' => now()])->save();
        });
    }

    public function verify(ExamCycle $cycle, User $user): void
    {
        $this->assertStatus($cycle, 'results_processed');

        if ($cycle->processed_by === $user->id) {
            throw new BusinessRuleException('Someone other than the person who processed the results must verify them.', 'SAME_PERSON', 409);
        }

        $cycle->forceFill(['status' => 'results_verified', 'verified_by' => $user->id, 'verified_at' => now()])->save();
    }

    public function publish(ExamCycle $cycle, User $user): void
    {
        $this->assertStatus($cycle, 'results_verified');
        $cycle->forceFill(['status' => 'published', 'published_by' => $user->id, 'published_at' => now()])->save();
    }

    // ----- revaluation -----------------------------------------------------

    public function requestRevaluation(ExamRegistration $registration, string $reason, User $user): RevaluationRequest
    {
        if ($registration->cycle->status !== 'published' || $registration->attendance !== 'present') {
            throw new BusinessRuleException('Revaluation is possible only for exams you wrote, after results are published.', 'NOT_REVALUABLE', 409);
        }

        if (RevaluationRequest::query()->where('exam_registration_id', $registration->id)->exists()) {
            throw new BusinessRuleException('You have already asked for revaluation of this course.', 'ALREADY_REQUESTED', 409);
        }

        return RevaluationRequest::query()->create(['exam_registration_id' => $registration->id, 'reason' => $reason, 'requested_by' => $user->id]);
    }

    /**
     * Completing with new marks regrades the course and recalculates the student's SGPA and CGPA.
     */
    public function decideRevaluation(RevaluationRequest $request, ?float $newMarks, ?string $remarks, User $user): void
    {
        if ($request->status !== 'pending') {
            throw new BusinessRuleException('This request has already been decided.', 'ALREADY_REVIEWED', 409);
        }

        $row = $request->registration;
        $max = (float) ExamSchedule::query()->where('exam_cycle_id', $row->exam_cycle_id)->where('course_id', $row->course_id)->value('max_marks');

        if ($newMarks === null && blank($remarks)) {
            throw new BusinessRuleException('Give the revised marks, or say why the request is rejected.', 'REMARKS_REQUIRED', 422);
        }

        if ($newMarks !== null && ($newMarks < 0 || $newMarks > $max)) {
            throw new BusinessRuleException('Marks must be between 0 and '.$max.'.', 'MARKS_OUT_OF_RANGE', 422);
        }

        DB::transaction(function () use ($request, $row, $newMarks, $remarks, $user, $max): void {
            $request->forceFill(['old_marks' => $row->external_marks, 'new_marks' => $newMarks, 'remarks' => $remarks, 'status' => $newMarks === null ? 'rejected' : 'completed', 'decided_by' => $user->id, 'decided_at' => now()])->save();

            if ($newMarks !== null) {
                $row->forceFill(['external_marks' => $newMarks, 'revision' => $row->revision + 1]);
                $this->grade($row, $max);
                $this->summarise($row->cycle, $row->student);
            }
        });
    }

    // ----- internals -------------------------------------------------------

    private function grade(ExamRegistration $row, float $externalMax): void
    {
        $result = ResultProcessor::grade(
            $row->internal_marks === null ? null : (float) $row->internal_marks,
            $row->internal_out_of === null ? null : (float) $row->internal_out_of,
            $row->external_marks === null ? null : (float) $row->external_marks,
            $externalMax,
            $row->attendance,
            $row->eligibility === 'eligible',
            $this->rules(),
        );

        $row->forceFill(['total_percent' => $result['total_percent'], 'grade' => $result['grade'], 'grade_points' => $result['points'], 'passed' => $result['passed']])->save();
    }

    private function summarise(ExamCycle $cycle, Student $student): void
    {
        $current = ExamRegistration::query()->with('course')->where('exam_cycle_id', $cycle->id)->where('student_id', $student->id)->get();
        $latest = $this->latestAttempts($student, $cycle->id, includeCycle: true);
        $points = fn (Collection $rows): array => $rows->map(fn (ExamRegistration $r): array => ['credits' => (float) $r->course->credits, 'points' => (float) $r->grade_points])->values()->all();

        ExamResult::query()->updateOrCreate(['exam_cycle_id' => $cycle->id, 'student_id' => $student->id], [
            'credits_registered' => $current->sum(fn (ExamRegistration $r): float => (float) $r->course->credits),
            'credits_earned' => $current->where('passed', true)->sum(fn (ExamRegistration $r): float => (float) $r->course->credits),
            'sgpa' => ResultProcessor::gpa($points($current)),
            'cgpa' => ResultProcessor::gpa($points($latest)),
            'backlogs' => $latest->where('passed', false)->count(),
        ]);
    }

    /**
     * The latest graded attempt of each course from published cycles, plus optionally one unpublished cycle.
     *
     * @return Collection<int, ExamRegistration>
     */
    private function latestAttempts(Student $student, ?int $cycleId, bool $includeCycle = false): Collection
    {
        return ExamRegistration::query()
            ->with(['course', 'cycle'])
            ->where('student_id', $student->id)
            ->whereNotNull('grade')
            ->where(fn ($query) => $query->whereHas('cycle', fn ($q) => $q->where('status', 'published'))
                ->when($includeCycle && $cycleId !== null, fn ($q) => $q->orWhere('exam_cycle_id', $cycleId)))
            ->when(! $includeCycle && $cycleId !== null, fn ($query) => $query->where('exam_cycle_id', '!=', $cycleId))
            ->orderBy('exam_cycle_id')
            ->get()
            ->keyBy('course_id')
            ->values();
    }

    private function assignScriptCodes(ExamCycle $cycle): void
    {
        $used = $cycle->registrations()->whereNotNull('script_code')->pluck('script_code')->all();

        $cycle->registrations()->where('eligibility', 'eligible')->whereNull('script_code')->get()->each(function (ExamRegistration $row) use (&$used): void {
            do {
                $code = (string) random_int(10000000, 99999999);
            } while (in_array($code, $used, true));

            $used[] = $code;
            $row->forceFill(['script_code' => $code])->save();
        });
    }

    /**
     * @return array{external_pass_percent: float, overall_pass_percent: float, grades: list<array{grade: string, min: float, points: float}>}
     */
    public function rules(): array
    {
        return [
            'external_pass_percent' => (float) $this->settings->get('exams', 'external_pass_percent'),
            'overall_pass_percent' => (float) $this->settings->get('exams', 'overall_pass_percent'),
            'grades' => array_values(array_map(fn (array $band): array => ['grade' => (string) $band['grade'], 'min' => (float) $band['min'], 'points' => (float) $band['points']], (array) $this->settings->get('exams', 'grades'))),
        ];
    }

    private function assertStatus(ExamCycle $cycle, string $status): void
    {
        if ($cycle->status !== $status) {
            throw new BusinessRuleException('This exam cycle is not at that stage.', 'INVALID_STATUS', 409);
        }
    }
}
