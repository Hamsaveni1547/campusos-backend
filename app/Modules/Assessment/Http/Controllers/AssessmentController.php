<?php

namespace App\Modules\Assessment\Http\Controllers;

use App\Models\User;
use App\Modules\Academics\Models\Course;
use App\Modules\Academics\Models\CourseOffering;
use App\Modules\Academics\Models\OfferingSection;
use App\Modules\Assessment\Authorization\AssessmentPermissions;
use App\Modules\Assessment\Models\Assessment;
use App\Modules\Assessment\Models\AssessmentGroup;
use App\Modules\Assessment\Models\AssessmentMark;
use App\Modules\Assessment\Models\CourseOutcome;
use App\Modules\Assessment\Models\InternalMark;
use App\Modules\Assessment\Models\InternalMarkSheet;
use App\Modules\Assessment\Models\MarkCorrection;
use App\Modules\Assessment\Services\AssessmentService;
use App\Modules\Institution\Models\Term;
use App\Modules\Students\Models\Student;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Http\ApiResponse;
use App\Shared\Settings\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * @tags Assessment
 */
class AssessmentController extends Controller
{
    public const KINDS = ['test', 'quiz', 'assignment', 'lab', 'seminar', 'project', 'other'];

    public const MARK_STATUSES = ['present', 'absent', 'exempt'];

    public const SHEET_STATUSES = ['draft', 'submitted', 'verified', 'locked'];

    public function __construct(private AssessmentService $assessment) {}

    /**
     * Assessment kinds, mark and sheet statuses, and the institution's rules.
     */
    public function options(SettingsRepository $settings): JsonResponse
    {
        $labels = fn (array $values): array => array_map(fn (string $value): array => ['value' => $value, 'label' => ucfirst($value)], $values);

        return ApiResponse::data([
            'kinds' => $labels(self::KINDS),
            'mark_statuses' => $labels(self::MARK_STATUSES),
            'sheet_statuses' => $labels(self::SHEET_STATUSES),
            'bloom_levels' => $labels(['remember', 'understand', 'apply', 'analyse', 'evaluate', 'create']),
            'round_internal' => (bool) $settings->get('assessment', 'round_internal'),
            'co_target_percent' => (float) $settings->get('assessment', 'co_target_percent'),
        ]);
    }

    // ----- course outcomes -------------------------------------------------

    /**
     * Course outcomes (CO1, CO2…) of a course.
     */
    public function outcomes(string $course): JsonResponse
    {
        $model = Course::query()->where('ulid', $course)->firstOrFail();

        return ApiResponse::list(CourseOutcome::query()->where('course_id', $model->id)->orderBy('code')->get(), fn (CourseOutcome $o): array => $this->outcome($o));
    }

    public function storeOutcome(Request $request, string $course): JsonResponse
    {
        Gate::authorize(AssessmentPermissions::SchemesManage);
        $model = Course::query()->where('ulid', $course)->firstOrFail();
        $validated = $this->validateOutcome($request, $model->id);
        $outcome = CourseOutcome::query()->create([...$validated, 'course_id' => $model->id]);

        return ApiResponse::data($this->outcome($outcome), 201);
    }

    public function updateOutcome(Request $request, CourseOutcome $outcome): JsonResponse
    {
        Gate::authorize(AssessmentPermissions::SchemesManage);
        $outcome->update($this->validateOutcome($request, $outcome->course_id, $outcome->id));

        return ApiResponse::data($this->outcome($outcome));
    }

    public function destroyOutcome(CourseOutcome $outcome): JsonResponse
    {
        Gate::authorize(AssessmentPermissions::SchemesManage);
        $used = Assessment::query()->whereHas('group.offering', fn ($query) => $query->where('course_id', $outcome->course_id))->whereJsonContains('outcome_codes', $outcome->code)->exists();

        if ($used) {
            throw new BusinessRuleException('Assessments are tagged with this outcome.', 'OUTCOME_IN_USE', 409);
        }

        $outcome->delete();

        return ApiResponse::message('Course outcome deleted.');
    }

    // ----- scheme ----------------------------------------------------------

    /**
     * The internal marks scheme of a course offering: parts (groups) with their assessments.
     */
    public function scheme(Request $request, string $offering): JsonResponse
    {
        $model = $this->offering($offering);
        $this->authorizeView($request, $model);

        $groups = AssessmentGroup::query()->with('assessments')->where('course_offering_id', $model->id)->orderBy('position')->get();

        return ApiResponse::data([
            'offering' => ['id' => $model->ulid, 'course' => ['id' => $model->course->ulid, 'code' => $model->course->code, 'title' => $model->course->title]],
            'can_manage' => $this->assessment->canManageOffering($this->user($request), $model),
            'total_weight' => (float) $groups->sum('weight'),
            'groups' => $groups->map(fn (AssessmentGroup $group): array => $this->group($group))->values(),
        ]);
    }

    public function storeGroup(Request $request, string $offering): JsonResponse
    {
        $model = $this->offering($offering);
        $this->authorizeManage($request, $model);

        return ApiResponse::data($this->group($this->assessment->saveGroup($model, $this->validateGroup($request))), 201);
    }

    public function updateGroup(Request $request, AssessmentGroup $group): JsonResponse
    {
        $this->authorizeManage($request, $group->offering);

        return ApiResponse::data($this->group($this->assessment->saveGroup($group->offering, $this->validateGroup($request), $group)));
    }

    public function destroyGroup(Request $request, AssessmentGroup $group): JsonResponse
    {
        $this->authorizeManage($request, $group->offering);
        $this->assessment->deleteGroup($group);

        return ApiResponse::message('Part deleted.');
    }

    public function storeAssessment(Request $request, AssessmentGroup $group): JsonResponse
    {
        $this->authorizeManage($request, $group->offering);
        $assessment = $this->assessment->saveAssessment($group, $this->validateAssessment($request));

        return ApiResponse::data($this->assessmentData($assessment), 201);
    }

    public function updateAssessment(Request $request, Assessment $assessment): JsonResponse
    {
        $this->authorizeManage($request, $assessment->group->offering);
        $assessment = $this->assessment->saveAssessment($assessment->group, $this->validateAssessment($request), $assessment);

        return ApiResponse::data($this->assessmentData($assessment));
    }

    public function destroyAssessment(Request $request, Assessment $assessment): JsonResponse
    {
        $this->authorizeManage($request, $assessment->group->offering);
        $this->assessment->deleteAssessment($assessment);

        return ApiResponse::message('Assessment deleted.');
    }

    // ----- marks -----------------------------------------------------------

    /**
     * Marks of one assessment for a class (`class` = class id), one row per student on the roster.
     */
    public function marks(Request $request, Assessment $assessment): JsonResponse
    {
        $class = $this->class((string) $request->validate(['class' => ['required', 'string', 'size:26']])['class']);
        $user = $this->user($request);
        $canEnter = $this->assessment->canTeach($user, $class);

        if (! $canEnter && ! $user->can(AssessmentPermissions::ReportsView)) {
            abort(403);
        }

        $marks = AssessmentMark::query()->where('assessment_id', $assessment->id)->get()->keyBy('student_id');
        $sheet = $this->assessment->sheet($class);

        return ApiResponse::data([
            'assessment' => $this->assessmentData($assessment),
            'class' => $this->classData($class),
            'editable' => $canEnter && $sheet->isEditable(),
            'sheet_status' => $sheet->status,
            'students' => $this->assessment->roster($class)->map(function (Student $student) use ($marks): array {
                $mark = $marks->get($student->id);

                return [...$this->studentData($student), 'mark_id' => $mark?->id, 'marks' => $mark?->marks === null ? null : (float) $mark->marks, 'status' => $mark?->status];
            })->values(),
        ]);
    }

    /**
     * Save marks: `class`, `marks` = {student id: {marks, status}}.
     */
    public function saveMarks(Request $request, Assessment $assessment): JsonResponse
    {
        $validated = $request->validate([
            'class' => ['required', 'string', 'size:26'],
            'marks' => ['required', 'array', 'min:1'],
            'marks.*.marks' => ['nullable', 'numeric', 'min:0'],
            'marks.*.status' => ['required', Rule::in(self::MARK_STATUSES)],
        ]);
        $class = $this->class($validated['class']);
        $user = $this->user($request);

        if (! $this->assessment->canTeach($user, $class)) {
            abort(403);
        }

        $ids = Student::query()->whereIn('ulid', array_keys($validated['marks']))->pluck('id', 'ulid');
        $entries = [];

        foreach ($validated['marks'] as $ulid => $entry) {
            $id = $ids[$ulid] ?? throw new BusinessRuleException('Some students are not in this class.', 'STUDENT_NOT_IN_CLASS', 422);
            $entries[(int) $id] = ['marks' => isset($entry['marks']) ? (float) $entry['marks'] : null, 'status' => $entry['status']];
        }

        $this->assessment->enterMarks($assessment, $class, $entries, $user);

        return ApiResponse::message('Marks saved.');
    }

    // ----- internal marks --------------------------------------------------

    /**
     * Internal marks sheet of a class: status, rules snapshot and each student's marks.
     */
    public function internal(Request $request, string $class): JsonResponse
    {
        $model = $this->class($class);
        $user = $this->user($request);
        $canTeach = $this->assessment->canTeach($user, $model);

        if (! $canTeach && ! $user->canAny([AssessmentPermissions::ReportsView, AssessmentPermissions::MarksVerify, AssessmentPermissions::MarksLock])) {
            abort(403);
        }

        return ApiResponse::data($this->sheetData($this->assessment->sheet($model), $model, $user, $canTeach));
    }

    public function compute(Request $request, string $class): JsonResponse
    {
        $model = $this->class($class);
        $this->authorizeTeach($request, $model);
        $sheet = $this->assessment->compute($model);

        return ApiResponse::data($this->sheetData($sheet, $model, $this->user($request), true));
    }

    public function submit(Request $request, string $class): JsonResponse
    {
        $model = $this->class($class);
        $this->authorizeTeach($request, $model);
        $sheet = $this->assessment->submit($model, $this->user($request));

        return ApiResponse::data($this->sheetData($sheet, $model, $this->user($request), true));
    }

    /**
     * Sheets waiting for verification or locking. Filters: `status`, `term`.
     */
    public function sheets(Request $request): JsonResponse
    {
        $user = $this->user($request);

        if (! $user->canAny([AssessmentPermissions::MarksVerify, AssessmentPermissions::MarksLock, AssessmentPermissions::ReportsView])) {
            abort(403);
        }

        $validated = $request->validate(['status' => ['nullable', Rule::in(self::SHEET_STATUSES)], 'term' => ['nullable', 'string', 'size:26']]);
        $term = isset($validated['term']) ? Term::query()->where('ulid', $validated['term'])->first() : Term::query()->where('is_current', true)->first();

        $sheets = InternalMarkSheet::query()
            ->with(['class.offering.course', 'class.section.batch', 'submitter'])
            ->withCount('marks')
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status), fn ($query) => $query->where('status', '!=', 'draft'))
            ->when($term, fn ($query) => $query->whereHas('class.offering', fn ($q) => $q->where('term_id', $term?->id)))
            ->latest('submitted_at')
            ->get();

        return ApiResponse::list($sheets, fn (InternalMarkSheet $sheet): array => [
            'id' => $sheet->ulid,
            'class' => $this->classData($sheet->class),
            'status' => $sheet->status,
            'students' => $sheet->marks_count,
            'submitted_by' => $sheet->submitter?->name,
            'submitted_at' => $sheet->submitted_at?->toIso8601String(),
            'can_verify' => $sheet->status === 'submitted' && $user->can(AssessmentPermissions::MarksVerify) && $sheet->submitted_by !== $user->id,
            'can_lock' => $sheet->status === 'verified' && $user->can(AssessmentPermissions::MarksLock),
        ]);
    }

    /**
     * Verify (`approve` true) or send back with `remarks`.
     */
    public function verify(Request $request, InternalMarkSheet $sheet): JsonResponse
    {
        Gate::authorize(AssessmentPermissions::MarksVerify);
        $validated = $request->validate(['approve' => ['required', 'boolean'], 'remarks' => ['nullable', 'string', 'max:500']]);
        $this->assessment->verify($sheet, (bool) $validated['approve'], $validated['remarks'] ?? null, $this->user($request));

        return ApiResponse::message($validated['approve'] ? 'Internal marks verified.' : 'Sent back to the teacher.');
    }

    public function lock(Request $request, InternalMarkSheet $sheet): JsonResponse
    {
        Gate::authorize(AssessmentPermissions::MarksLock);
        $this->assessment->lock($sheet, $this->user($request));

        return ApiResponse::message('Internal marks locked.');
    }

    /**
     * Course outcome attainment of a class.
     */
    public function attainment(Request $request, string $class): JsonResponse
    {
        $model = $this->class($class);
        $user = $this->user($request);

        if (! $this->assessment->canTeach($user, $model) && ! $user->can(AssessmentPermissions::ReportsView)) {
            abort(403);
        }

        return ApiResponse::data(['class' => $this->classData($model), 'outcomes' => $this->assessment->attainment($model)]);
    }

    // ----- corrections -----------------------------------------------------

    /**
     * Mark corrections. Approvers see all; others see their own. Filter: `filter[status]`.
     */
    public function corrections(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $status = $request->validate(['filter.status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])]])['filter']['status'] ?? null;

        $corrections = MarkCorrection::query()
            ->with(['mark.assessment', 'mark.student.user', 'requester'])
            ->when(! $user->can(AssessmentPermissions::CorrectionsApprove), fn ($query) => $query->where('requested_by', $user->id))
            ->when($status, fn ($query, string $value) => $query->where('status', $value))
            ->latest('id')
            ->paginate(25);

        return ApiResponse::paginated($corrections, fn (MarkCorrection $c): array => [
            'id' => $c->ulid,
            'assessment' => $c->mark->assessment->name,
            'student' => $this->studentData($c->mark->student),
            'current' => ['marks' => $c->mark->marks === null ? null : (float) $c->mark->marks, 'status' => $c->mark->status],
            'requested' => ['marks' => $c->requested_marks === null ? null : (float) $c->requested_marks, 'status' => $c->requested_status],
            'reason' => $c->reason,
            'status' => $c->status,
            'remarks' => $c->remarks,
            'requested_by' => $c->requester?->name,
            'requested_at' => $c->created_at?->toIso8601String(),
        ]);
    }

    /**
     * Ask to change a mark after the class's internal marks are submitted.
     */
    public function requestCorrection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mark_id' => ['required', 'integer'],
            'marks' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', Rule::in(self::MARK_STATUSES)],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $mark = AssessmentMark::query()->findOrFail($validated['mark_id']);
        $student = $mark->student;
        $class = OfferingSection::query()->where('course_offering_id', $mark->assessment->group->course_offering_id)->where('section_id', $student->section_id)->firstOrFail();
        $this->authorizeTeach($request, $class);

        $correction = $this->assessment->requestCorrection($mark, isset($validated['marks']) ? (float) $validated['marks'] : null, $validated['status'], $validated['reason'], $this->user($request));

        return ApiResponse::data(['id' => $correction->ulid], 201);
    }

    public function decideCorrection(Request $request, MarkCorrection $correction): JsonResponse
    {
        Gate::authorize(AssessmentPermissions::CorrectionsApprove);
        $validated = $request->validate(['approve' => ['required', 'boolean'], 'remarks' => ['nullable', 'string', 'max:500']]);
        $this->assessment->decideCorrection($correction, (bool) $validated['approve'], $validated['remarks'] ?? null, $this->user($request));

        return ApiResponse::message($validated['approve'] ? 'Correction approved.' : 'Correction rejected.');
    }

    // ----- students --------------------------------------------------------

    /**
     * The signed-in student's internal marks this term. Marks show once the sheet is verified.
     */
    public function mine(Request $request): JsonResponse
    {
        $student = Student::query()->where('user_id', $this->user($request)->id)->first() ?? throw new BusinessRuleException('You do not have a student record in this institution.', 'NOT_A_STUDENT', 404);
        $term = Term::query()->where('is_current', true)->first();
        $classes = $term === null ? collect() : $this->assessment->studentClasses($student, $term->id);
        $sheets = InternalMarkSheet::query()->whereIn('offering_section_id', $classes->pluck('id'))->get()->keyBy('offering_section_id');

        return ApiResponse::data([
            'term' => $term === null ? null : ['id' => $term->ulid, 'name' => $term->name],
            'courses' => $classes->map(function (OfferingSection $class) use ($sheets, $student): array {
                $sheet = $sheets->get($class->id);
                $published = $sheet !== null && in_array($sheet->status, ['verified', 'locked'], true);
                $mark = $published ? InternalMark::query()->where('internal_mark_sheet_id', $sheet->id)->where('student_id', $student->id)->first() : null;
                $assessments = Assessment::query()->whereHas('group', fn ($query) => $query->where('course_offering_id', $class->course_offering_id))->with(['marks' => fn ($query) => $query->where('student_id', $student->id)])->orderBy('held_on')->get();

                return [
                    'class_id' => $class->ulid,
                    'course' => ['code' => $class->offering->course->code, 'title' => $class->offering->course->title],
                    'assessments' => $assessments->map(fn (Assessment $a): array => ['name' => $a->name, 'max_marks' => (float) $a->max_marks, 'marks' => $a->marks->first()?->marks === null ? null : (float) $a->marks->first()->marks, 'status' => $a->marks->first()?->status])->values(),
                    'internal' => $mark === null ? null : ['final' => (float) $mark->final, 'out_of' => (float) ($sheet->rules['total'] ?? 0), 'breakdown' => $mark->breakdown],
                ];
            })->values(),
        ]);
    }

    // ----- helpers ---------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function sheetData(InternalMarkSheet $sheet, OfferingSection $class, User $user, bool $canTeach): array
    {
        $marks = InternalMark::query()->where('internal_mark_sheet_id', $sheet->id)->get()->keyBy('student_id');
        $sheet->loadMissing(['submitter', 'verifier']);

        return [
            'id' => $sheet->ulid,
            'class' => $this->classData($class),
            'status' => $sheet->status,
            'remarks' => $sheet->remarks,
            'rules' => $sheet->rules,
            'computed_at' => $sheet->computed_at?->toIso8601String(),
            'submitted_by' => $sheet->submitter?->name,
            'submitted_at' => $sheet->submitted_at?->toIso8601String(),
            'verified_by' => $sheet->verifier?->name,
            'verified_at' => $sheet->verified_at?->toIso8601String(),
            'can_edit' => $canTeach && $sheet->isEditable(),
            'can_verify' => $sheet->status === 'submitted' && $user->can(AssessmentPermissions::MarksVerify) && $sheet->submitted_by !== $user->id,
            'can_lock' => $sheet->status === 'verified' && $user->can(AssessmentPermissions::MarksLock),
            'students' => $this->assessment->roster($class)->map(function (Student $student) use ($marks): array {
                $mark = $marks->get($student->id);

                return [...$this->studentData($student), 'computed' => $mark === null ? null : (float) $mark->computed, 'final' => $mark === null ? null : (float) $mark->final, 'breakdown' => $mark?->breakdown];
            })->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function group(AssessmentGroup $group): array
    {
        $group->loadMissing('assessments');

        return [
            'id' => $group->ulid,
            'name' => $group->name,
            'best_of' => $group->best_of,
            'weight' => (float) $group->weight,
            'position' => $group->position,
            'assessments' => $group->assessments->map(fn (Assessment $a): array => $this->assessmentData($a))->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assessmentData(Assessment $assessment): array
    {
        return [
            'id' => $assessment->ulid,
            'name' => $assessment->name,
            'kind' => $assessment->kind,
            'held_on' => $assessment->held_on?->toDateString(),
            'max_marks' => (float) $assessment->max_marks,
            'outcome_codes' => $assessment->outcome_codes ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function outcome(CourseOutcome $outcome): array
    {
        return ['id' => $outcome->ulid, 'code' => $outcome->code, 'description' => $outcome->description, 'bloom_level' => $outcome->bloom_level];
    }

    /**
     * @return array<string, mixed>
     */
    private function classData(OfferingSection $class): array
    {
        $class->loadMissing(['offering.course', 'section.batch']);

        return [
            'id' => $class->ulid,
            'offering_id' => $class->offering->ulid,
            'course' => ['code' => $class->offering->course->code, 'title' => $class->offering->course->title],
            'section' => $class->section->batch->name.' '.$class->section->name,
        ];
    }

    /**
     * @return array{id: string, name: string|null, roll_no: string|null, register_no: string|null}
     */
    private function studentData(Student $student): array
    {
        return ['id' => $student->ulid, 'name' => $student->user->name, 'roll_no' => $student->roll_no, 'register_no' => $student->register_no];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateOutcome(Request $request, int $courseId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:10', Rule::unique('course_outcomes', 'code')->where('course_id', $courseId)->ignore($ignoreId)],
            'description' => ['required', 'string', 'max:500'],
            'bloom_level' => ['nullable', Rule::in(['remember', 'understand', 'apply', 'analyse', 'evaluate', 'create'])],
        ]);
    }

    /**
     * @return array{name: string, best_of?: int|null, weight: float}
     */
    private function validateGroup(Request $request): array
    {
        /** @var array{name: string, best_of?: int|null, weight: float} */
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'best_of' => ['nullable', 'integer', 'min:1', 'max:20'],
            'weight' => ['required', 'numeric', 'min:0', 'max:1000'],
        ]);
    }

    /**
     * @return array{name: string, kind: string, held_on?: string|null, max_marks: float, outcome_codes?: list<string>|null}
     */
    private function validateAssessment(Request $request): array
    {
        /** @var array{name: string, kind: string, held_on?: string|null, max_marks: float, outcome_codes?: list<string>|null} */
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['required', Rule::in(self::KINDS)],
            'held_on' => ['nullable', 'date'],
            'max_marks' => ['required', 'numeric', 'min:1', 'max:1000'],
            'outcome_codes' => ['nullable', 'array'],
            'outcome_codes.*' => ['string', 'max:10'],
        ]);
    }

    private function authorizeView(Request $request, CourseOffering $offering): void
    {
        $user = $this->user($request);

        if (! $this->assessment->canManageOffering($user, $offering) && ! $user->can(AssessmentPermissions::ReportsView)) {
            abort(403);
        }
    }

    private function authorizeManage(Request $request, CourseOffering $offering): void
    {
        if (! $this->assessment->canManageOffering($this->user($request), $offering)) {
            abort(403);
        }
    }

    private function authorizeTeach(Request $request, OfferingSection $class): void
    {
        if (! $this->assessment->canTeach($this->user($request), $class)) {
            abort(403);
        }
    }

    private function offering(string $ulid): CourseOffering
    {
        return CourseOffering::query()->with('course')->where('ulid', $ulid)->firstOrFail();
    }

    private function class(string $ulid): OfferingSection
    {
        return OfferingSection::query()->with(['offering.course', 'section.batch'])->where('ulid', $ulid)->firstOrFail();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
