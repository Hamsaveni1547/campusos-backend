<?php

namespace App\Modules\Assessment\Services;

use App\Models\User;
use App\Modules\Academics\Models\CourseOffering;
use App\Modules\Academics\Models\OfferingSection;
use App\Modules\Academics\Services\AcademicsService;
use App\Modules\Assessment\Authorization\AssessmentPermissions;
use App\Modules\Assessment\Models\Assessment;
use App\Modules\Assessment\Models\AssessmentGroup;
use App\Modules\Assessment\Models\AssessmentMark;
use App\Modules\Assessment\Models\Assignment;
use App\Modules\Assessment\Models\AssignmentSubmission;
use App\Modules\Assessment\Models\CourseOutcome;
use App\Modules\Assessment\Models\InternalMark;
use App\Modules\Assessment\Models\InternalMarkSheet;
use App\Modules\Assessment\Models\MarkCorrection;
use App\Modules\Assessment\Support\InternalMarksCalculator;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Students\Models\Student;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Files\StoredFile;
use App\Shared\Settings\SettingsRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Internal assessment: scheme, marks entry, computation, approval, corrections, outcomes and assignments.
 */
class AssessmentService
{
    public function __construct(
        private AcademicsService $academics,
        private AttendanceService $attendance,
        private SettingsRepository $settings,
    ) {}

    // ----- access ----------------------------------------------------------

    /**
     * Teacher of this class, or someone who manages schemes.
     */
    public function canTeach(User $user, OfferingSection $class): bool
    {
        return $user->can(AssessmentPermissions::SchemesManage)
            || ($user->can(AssessmentPermissions::MarksEnter) && $this->academics->teaches($user, $class));
    }

    /**
     * Teacher of any section of the offering, or someone who manages schemes.
     */
    public function canManageOffering(User $user, CourseOffering $offering): bool
    {
        if ($user->can(AssessmentPermissions::SchemesManage)) {
            return true;
        }

        return $user->can(AssessmentPermissions::MarksEnter)
            && $offering->sections()->whereHas('allocations.employee', fn ($query) => $query->where('user_id', $user->id))->exists();
    }

    /**
     * @return Collection<int, Student>
     */
    public function roster(OfferingSection $class): Collection
    {
        return $this->attendance->roster($class);
    }

    /**
     * Classes a student is registered in for a term.
     *
     * @return Collection<int, OfferingSection>
     */
    public function studentClasses(Student $student, int $termId): Collection
    {
        return OfferingSection::query()
            ->with('offering.course')
            ->where('section_id', $student->section_id)
            ->whereHas('offering', fn ($query) => $query->where('term_id', $termId))
            ->whereExists(fn ($query) => $query->select(DB::raw(1))->from('course_registrations')
                ->join('term_registrations', 'term_registrations.id', '=', 'course_registrations.term_registration_id')
                ->whereColumn('course_registrations.course_offering_id', 'offering_sections.course_offering_id')
                ->where('term_registrations.student_id', $student->id)
                ->where('term_registrations.status', '!=', 'rejected'))
            ->get();
    }

    public function sheet(OfferingSection $class): InternalMarkSheet
    {
        return InternalMarkSheet::query()->firstOrCreate(['offering_section_id' => $class->id], ['institution_id' => $class->institution_id]);
    }

    // ----- scheme ----------------------------------------------------------

    /**
     * @param  array{name: string, best_of?: int|null, weight: float}  $data
     */
    public function saveGroup(CourseOffering $offering, array $data, ?AssessmentGroup $group = null): AssessmentGroup
    {
        $this->assertSchemeOpen($offering);

        if ($group === null) {
            $data['position'] = (int) $offering->hasMany(AssessmentGroup::class)->max('position') + 1;
        }

        $group ??= new AssessmentGroup(['course_offering_id' => $offering->id]);
        $group->fill($data)->save();

        return $group;
    }

    public function deleteGroup(AssessmentGroup $group): void
    {
        $this->assertSchemeOpen($group->offering);

        if ($group->assessments()->whereHas('marks')->exists()) {
            throw new BusinessRuleException('This part already has marks. Remove its assessments first.', 'GROUP_IN_USE', 409);
        }

        $group->delete();
    }

    /**
     * @param  array{name: string, kind: string, held_on?: string|null, max_marks: float, outcome_codes?: list<string>|null}  $data
     */
    public function saveAssessment(AssessmentGroup $group, array $data, ?Assessment $assessment = null): Assessment
    {
        $this->assertSchemeOpen($group->offering);

        if (isset($data['outcome_codes'])) {
            $known = CourseOutcome::query()->where('course_id', $group->offering->course_id)->pluck('code')->all();
            $unknown = array_diff($data['outcome_codes'], $known);

            if ($unknown !== []) {
                throw new BusinessRuleException('Unknown course outcomes: '.implode(', ', $unknown).'.', 'OUTCOME_INVALID', 422);
            }
        }

        if ($assessment !== null && (float) $data['max_marks'] < (float) $assessment->marks()->max('marks')) {
            throw new BusinessRuleException('Some students already have more marks than that.', 'MAX_BELOW_MARKS', 422);
        }

        $assessment ??= new Assessment(['assessment_group_id' => $group->id]);
        $assessment->fill($data)->save();

        return $assessment;
    }

    public function deleteAssessment(Assessment $assessment): void
    {
        $this->assertSchemeOpen($assessment->group->offering);

        if ($assessment->marks()->exists()) {
            throw new BusinessRuleException('This assessment already has marks.', 'ASSESSMENT_IN_USE', 409);
        }

        $assessment->delete();
    }

    // ----- marks -----------------------------------------------------------

    /**
     * @param  array<int, array{marks: float|null, status: string}>  $entries  student id => mark
     */
    public function enterMarks(Assessment $assessment, OfferingSection $class, array $entries, User $user): void
    {
        if ($class->course_offering_id !== $assessment->group->course_offering_id) {
            throw new BusinessRuleException('This assessment belongs to another course.', 'CLASS_MISMATCH', 422);
        }

        if (! $this->sheet($class)->isEditable()) {
            throw new BusinessRuleException('Internal marks of this class are submitted. Ask for a correction instead.', 'MARKS_LOCKED', 409);
        }

        $roster = $this->roster($class)->pluck('id')->all();

        foreach ($entries as $studentId => $entry) {
            if (! in_array($studentId, $roster, true)) {
                throw new BusinessRuleException('Some students are not in this class.', 'STUDENT_NOT_IN_CLASS', 422);
            }

            $this->assertMark($assessment, $entry['marks'], $entry['status']);
        }

        DB::transaction(function () use ($assessment, $entries, $user): void {
            foreach ($entries as $studentId => $entry) {
                AssessmentMark::query()->updateOrCreate(
                    ['assessment_id' => $assessment->id, 'student_id' => $studentId],
                    ['institution_id' => $assessment->institution_id, 'marks' => $entry['status'] === 'present' ? $entry['marks'] : null, 'status' => $entry['status'], 'entered_by' => $user->id],
                );
            }
        });
    }

    // ----- internal marks --------------------------------------------------

    public function compute(OfferingSection $class): InternalMarkSheet
    {
        $sheet = $this->sheet($class);

        if (! $sheet->isEditable()) {
            throw new BusinessRuleException('Internal marks of this class are already submitted.', 'MARKS_LOCKED', 409);
        }

        $this->computeInto($sheet, $class, $this->roster($class)->pluck('id')->all());

        return $sheet;
    }

    /**
     * @param  list<int>  $studentIds
     */
    private function computeInto(InternalMarkSheet $sheet, OfferingSection $class, array $studentIds): void
    {
        $groups = AssessmentGroup::query()->with('assessments')->where('course_offering_id', $class->course_offering_id)->orderBy('position')->get();

        if ($groups->isEmpty()) {
            throw new BusinessRuleException('Set up the internal marks scheme first.', 'NO_SCHEME', 422);
        }

        $rules = $groups->map(fn (AssessmentGroup $group): array => [
            'name' => $group->name,
            'best_of' => $group->best_of,
            'weight' => (float) $group->weight,
            'assessments' => $group->assessments->map(fn (Assessment $a): array => ['id' => $a->id, 'name' => $a->name, 'max' => (float) $a->max_marks])->values()->all(),
        ])->values()->all();

        $marks = [];
        AssessmentMark::query()
            ->whereIn('assessment_id', $groups->flatMap->assessments->pluck('id'))
            ->whereIn('student_id', $studentIds)
            ->get()
            ->each(function (AssessmentMark $mark) use (&$marks): void {
                $marks[$mark->assessment_id][$mark->student_id] = ['marks' => $mark->marks === null ? null : (float) $mark->marks, 'status' => $mark->status];
            });

        $results = InternalMarksCalculator::compute($rules, $marks, $studentIds, (bool) $this->settings->get('assessment', 'round_internal'));

        DB::transaction(function () use ($sheet, $results, $rules): void {
            foreach ($results as $studentId => $result) {
                InternalMark::query()->updateOrCreate(
                    ['internal_mark_sheet_id' => $sheet->id, 'student_id' => $studentId],
                    ['institution_id' => $sheet->institution_id, 'computed' => $result['computed'], 'final' => $result['final'], 'breakdown' => $result['breakdown']],
                );
            }

            $sheet->forceFill(['rules' => ['groups' => $rules, 'total' => array_sum(array_column($rules, 'weight'))], 'computed_at' => now()])->save();
        });
    }

    public function submit(OfferingSection $class, User $teacher): InternalMarkSheet
    {
        $sheet = $this->compute($class);
        $sheet->forceFill(['status' => 'submitted', 'submitted_by' => $teacher->id, 'submitted_at' => now(), 'remarks' => null])->save();

        return $sheet;
    }

    /**
     * Verify, or send back to the teacher with remarks.
     */
    public function verify(InternalMarkSheet $sheet, bool $approve, ?string $remarks, User $verifier): InternalMarkSheet
    {
        if ($sheet->status !== 'submitted') {
            throw new BusinessRuleException('Only submitted marks can be verified.', 'INVALID_STATUS', 409);
        }

        if ($sheet->submitted_by === $verifier->id) {
            throw new BusinessRuleException('Someone other than the person who submitted must verify.', 'SAME_PERSON', 409);
        }

        if (! $approve && blank($remarks)) {
            throw new BusinessRuleException('Say what needs to change.', 'REMARKS_REQUIRED', 422);
        }

        $sheet->forceFill($approve
            ? ['status' => 'verified', 'verified_by' => $verifier->id, 'verified_at' => now(), 'remarks' => $remarks]
            : ['status' => 'draft', 'remarks' => $remarks])->save();

        return $sheet;
    }

    public function lock(InternalMarkSheet $sheet, User $user): InternalMarkSheet
    {
        if ($sheet->status !== 'verified') {
            throw new BusinessRuleException('Only verified marks can be locked.', 'INVALID_STATUS', 409);
        }

        $sheet->forceFill(['status' => 'locked', 'locked_by' => $user->id, 'locked_at' => now()])->save();

        return $sheet;
    }

    // ----- corrections -----------------------------------------------------

    public function requestCorrection(AssessmentMark $mark, ?float $marks, string $status, string $reason, User $user): MarkCorrection
    {
        $this->assertMark($mark->assessment, $marks, $status);

        if (MarkCorrection::query()->where('assessment_mark_id', $mark->id)->where('status', 'pending')->exists()) {
            throw new BusinessRuleException('A correction for this mark is already waiting.', 'CORRECTION_PENDING', 409);
        }

        return MarkCorrection::query()->create(['assessment_mark_id' => $mark->id, 'requested_marks' => $status === 'present' ? $marks : null, 'requested_status' => $status, 'reason' => $reason, 'requested_by' => $user->id]);
    }

    /**
     * Approving changes the mark and recomputes that student's internal mark (the sheet keeps its status).
     */
    public function decideCorrection(MarkCorrection $correction, bool $approve, ?string $remarks, User $reviewer): MarkCorrection
    {
        if ($correction->status !== 'pending') {
            throw new BusinessRuleException('This request has already been reviewed.', 'ALREADY_REVIEWED', 409);
        }

        if (! $approve && blank($remarks)) {
            throw new BusinessRuleException('Say why the request is rejected.', 'REMARKS_REQUIRED', 422);
        }

        DB::transaction(function () use ($correction, $approve, $remarks, $reviewer): void {
            if ($approve) {
                $mark = $correction->mark;
                $mark->update(['marks' => $correction->requested_marks, 'status' => $correction->requested_status]);

                $class = OfferingSection::query()
                    ->where('course_offering_id', $mark->assessment->group->course_offering_id)
                    ->where('section_id', Student::query()->whereKey($mark->student_id)->value('section_id'))
                    ->first();
                $sheet = $class !== null ? InternalMarkSheet::query()->where('offering_section_id', $class->id)->first() : null;

                if ($class !== null && $sheet !== null && $sheet->computed_at !== null) {
                    $this->computeInto($sheet, $class, [$mark->student_id]);
                }
            }

            $correction->forceFill(['status' => $approve ? 'approved' : 'rejected', 'remarks' => $remarks, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now()])->save();
        });

        return $correction;
    }

    // ----- outcomes --------------------------------------------------------

    /**
     * Per course outcome: share of students scoring at least the target % on assessments tagged with it.
     *
     * @return list<array{code: string, description: string, assessments: int, students: int, attained: int, percentage: float|null}>
     */
    public function attainment(OfferingSection $class): array
    {
        $target = (float) $this->settings->get('assessment', 'co_target_percent');
        $studentIds = $this->roster($class)->pluck('id')->all();
        $assessments = Assessment::query()->whereHas('group', fn ($query) => $query->where('course_offering_id', $class->course_offering_id))->get();
        $marks = AssessmentMark::query()->whereIn('assessment_id', $assessments->pluck('id'))->whereIn('student_id', $studentIds)->where('status', '!=', 'exempt')->get()->groupBy('assessment_id');

        return CourseOutcome::query()->where('course_id', $class->offering->course_id)->orderBy('code')->get()->map(function (CourseOutcome $outcome) use ($assessments, $marks, $target): array {
            $tagged = $assessments->filter(fn (Assessment $a): bool => in_array($outcome->code, $a->outcome_codes ?? [], true));
            $scores = [];

            foreach ($tagged as $assessment) {
                foreach ($marks->get($assessment->id, collect()) as $mark) {
                    $scores[$mark->student_id]['got'] = ($scores[$mark->student_id]['got'] ?? 0) + (float) ($mark->marks ?? 0);
                    $scores[$mark->student_id]['max'] = ($scores[$mark->student_id]['max'] ?? 0) + (float) $assessment->max_marks;
                }
            }

            $attained = collect($scores)->filter(fn (array $s): bool => $s['max'] > 0 && $target <= $s['got'] * 100 / $s['max'])->count();

            return [
                'code' => $outcome->code,
                'description' => $outcome->description,
                'assessments' => $tagged->count(),
                'students' => count($scores),
                'attained' => $attained,
                'percentage' => $scores === [] ? null : round($attained * 100 / count($scores), 2),
            ];
        })->values()->all();
    }

    // ----- assignments -----------------------------------------------------

    public function submitAssignment(Assignment $assignment, Student $student, ?StoredFile $file, ?string $text): AssignmentSubmission
    {
        $late = now()->gt($assignment->due_at);

        if ($late && ! $assignment->allow_late) {
            throw new BusinessRuleException('The deadline has passed.', 'DEADLINE_PASSED', 409);
        }

        $existing = AssignmentSubmission::query()->where('assignment_id', $assignment->id)->where('student_id', $student->id)->first();

        if ($existing?->evaluated_at !== null) {
            throw new BusinessRuleException('This submission is already evaluated.', 'ALREADY_EVALUATED', 409);
        }

        if ($file === null && blank($text)) {
            throw new BusinessRuleException('Attach a file or write your answer.', 'EMPTY_SUBMISSION', 422);
        }

        return AssignmentSubmission::query()->updateOrCreate(
            ['assignment_id' => $assignment->id, 'student_id' => $student->id],
            ['institution_id' => $assignment->institution_id, 'file_id' => $file?->id, 'text' => $text, 'submitted_at' => now(), 'is_late' => $late],
        );
    }

    /**
     * Marks and feedback; when the assignment feeds an assessment, the scaled mark is written there.
     */
    public function evaluate(AssignmentSubmission $submission, float $marks, ?string $feedback, User $teacher): AssignmentSubmission
    {
        $assignment = $submission->assignment;

        if ($marks < 0 || $marks > (float) $assignment->max_marks) {
            throw new BusinessRuleException('Marks must be between 0 and '.$assignment->max_marks.'.', 'MARKS_OUT_OF_RANGE', 422);
        }

        DB::transaction(function () use ($submission, $marks, $feedback, $teacher, $assignment): void {
            $submission->forceFill(['marks' => $marks, 'feedback' => $feedback, 'evaluated_by' => $teacher->id, 'evaluated_at' => now()])->save();

            if ($assignment->assessment !== null) {
                $class = OfferingSection::query()->where('course_offering_id', $assignment->course_offering_id)->where('section_id', $submission->student->section_id)->firstOrFail();
                $scaled = round($marks / (float) $assignment->max_marks * (float) $assignment->assessment->max_marks, 2);
                $this->enterMarks($assignment->assessment, $class, [$submission->student_id => ['marks' => $scaled, 'status' => 'present']], $teacher);
            }
        });

        return $submission;
    }

    // ----- internals -------------------------------------------------------

    private function assertMark(Assessment $assessment, ?float $marks, string $status): void
    {
        if (! in_array($status, ['present', 'absent', 'exempt'], true)) {
            throw new BusinessRuleException('Unknown mark status.', 'STATUS_INVALID', 422);
        }

        if ($status === 'present' && ($marks === null || $marks < 0 || $marks > (float) $assessment->max_marks)) {
            throw new BusinessRuleException('Marks must be between 0 and '.$assessment->max_marks.'.', 'MARKS_OUT_OF_RANGE', 422);
        }
    }

    private function assertSchemeOpen(CourseOffering $offering): void
    {
        $locked = InternalMarkSheet::query()->whereHas('class', fn ($query) => $query->where('course_offering_id', $offering->id))->where('status', '!=', 'draft')->exists();

        if ($locked) {
            throw new BusinessRuleException('Internal marks of a section are already submitted, so the scheme cannot change.', 'SCHEME_LOCKED', 409);
        }
    }
}
