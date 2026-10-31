<?php

namespace App\Modules\Assessment\Http\Controllers;

use App\Models\User;
use App\Modules\Academics\Models\CourseOffering;
use App\Modules\Assessment\Authorization\AssessmentPermissions;
use App\Modules\Assessment\Models\Assessment;
use App\Modules\Assessment\Models\Assignment;
use App\Modules\Assessment\Models\AssignmentSubmission;
use App\Modules\Assessment\Services\AssessmentService;
use App\Modules\Institution\Models\Term;
use App\Modules\Students\Models\Student;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Files\StoredFile;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @tags Assessment
 */
class AssignmentController extends Controller
{
    public function __construct(private AssessmentService $assessment) {}

    /**
     * Assignments of a course offering with submission counts.
     */
    public function index(Request $request, string $offering): JsonResponse
    {
        $model = $this->offering($offering);
        $this->authorizeView($request, $model);

        $assignments = Assignment::query()->with('assessment')->withCount(['submissions', 'submissions as evaluated_count' => fn ($query) => $query->whereNotNull('evaluated_at')])
            ->where('course_offering_id', $model->id)->latest('due_at')->get();

        return ApiResponse::list($assignments, fn (Assignment $a): array => [...$this->data($a), 'submissions' => $a->submissions_count, 'evaluated' => $a->evaluated_count]);
    }

    public function store(Request $request, string $offering): JsonResponse
    {
        $model = $this->offering($offering);
        $this->authorizeManage($request, $model);
        $assignment = Assignment::query()->create([...$this->validated($request, $model), 'course_offering_id' => $model->id]);

        return ApiResponse::data($this->data($assignment), 201);
    }

    public function update(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorizeManage($request, $assignment->offering);
        $assignment->update($this->validated($request, $assignment->offering));

        return ApiResponse::data($this->data($assignment));
    }

    public function destroy(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorizeManage($request, $assignment->offering);

        return ApiResponse::deleteUnlessUsed($assignment, ['submissions' => 'submissions'], 'assignment');
    }

    /**
     * Submissions of an assignment.
     */
    public function submissions(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorizeView($request, $assignment->offering);

        return ApiResponse::data([
            'assignment' => $this->data($assignment),
            'submissions' => $assignment->submissions()->with(['student.user', 'file'])->orderBy('submitted_at')->get()->map(fn (AssignmentSubmission $s): array => [
                'id' => $s->ulid,
                'student' => ['id' => $s->student->ulid, 'name' => $s->student->user->name, 'roll_no' => $s->student->roll_no, 'register_no' => $s->student->register_no],
                'text' => $s->text,
                'file' => $s->file === null ? null : ['id' => $s->file->ulid, 'name' => $s->file->original_name],
                'submitted_at' => $s->submitted_at->toIso8601String(),
                'is_late' => $s->is_late,
                'marks' => $s->marks === null ? null : (float) $s->marks,
                'feedback' => $s->feedback,
                'evaluated_at' => $s->evaluated_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function evaluate(Request $request, AssignmentSubmission $submission): JsonResponse
    {
        $this->authorizeManage($request, $submission->assignment->offering);
        $validated = $request->validate(['marks' => ['required', 'numeric', 'min:0'], 'feedback' => ['nullable', 'string', 'max:2000']]);
        $this->assessment->evaluate($submission, (float) $validated['marks'], $validated['feedback'] ?? null, $this->user($request));

        return ApiResponse::message('Submission evaluated.');
    }

    // ----- students --------------------------------------------------------

    /**
     * The signed-in student's assignments this term with their submissions.
     */
    public function mine(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $term = Term::query()->where('is_current', true)->first();
        $offeringIds = $term === null ? [] : $this->assessment->studentClasses($student, $term->id)->pluck('course_offering_id')->all();

        $assignments = Assignment::query()->with(['offering.course', 'file', 'submissions' => fn ($query) => $query->where('student_id', $student->id)->with('file')])
            ->whereIn('course_offering_id', $offeringIds)->orderBy('due_at')->get();

        return ApiResponse::list($assignments, function (Assignment $a): array {
            $submission = $a->submissions->first();

            return [
                ...$this->data($a),
                'course' => ['code' => $a->offering->course->code, 'title' => $a->offering->course->title],
                'can_submit' => ($submission?->evaluated_at === null) && ($a->allow_late || now()->lte($a->due_at)),
                'submission' => $submission === null ? null : [
                    'text' => $submission->text,
                    'file' => $submission->file === null ? null : ['id' => $submission->file->ulid, 'name' => $submission->file->original_name],
                    'submitted_at' => $submission->submitted_at->toIso8601String(),
                    'is_late' => $submission->is_late,
                    'marks' => $submission->marks === null ? null : (float) $submission->marks,
                    'feedback' => $submission->feedback,
                ],
            ];
        });
    }

    /**
     * Submit or replace an answer: `file_id` (uploaded file) and/or `text`.
     */
    public function submit(Request $request, Assignment $assignment): JsonResponse
    {
        $student = $this->student($request);
        $validated = $request->validate(['file_id' => ['nullable', 'string', 'size:26'], 'text' => ['nullable', 'string', 'max:20000']]);
        $term = Term::query()->where('is_current', true)->first();
        $enrolled = $term !== null && $this->assessment->studentClasses($student, $term->id)->contains('course_offering_id', $assignment->course_offering_id);

        if (! $enrolled) {
            abort(403);
        }

        $file = null;

        if (isset($validated['file_id'])) {
            $file = StoredFile::query()->where('ulid', $validated['file_id'])->where('uploaded_by', $this->user($request)->id)->whereIn('purpose', ['document', 'attachment'])->first()
                ?? throw new BusinessRuleException('Upload the file first.', 'FILE_INVALID', 422);
        }

        $submission = $this->assessment->submitAssignment($assignment, $student, $file, $validated['text'] ?? null);

        return ApiResponse::data(['id' => $submission->ulid, 'is_late' => $submission->is_late], 201);
    }

    // ----- helpers ---------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function data(Assignment $assignment): array
    {
        $assignment->loadMissing(['assessment', 'file']);

        return [
            'id' => $assignment->ulid,
            'title' => $assignment->title,
            'instructions' => $assignment->instructions,
            'file' => $assignment->file === null ? null : ['id' => $assignment->file->ulid, 'name' => $assignment->file->original_name],
            'due_at' => $assignment->due_at->toIso8601String(),
            'max_marks' => (float) $assignment->max_marks,
            'allow_late' => $assignment->allow_late,
            'assessment' => $assignment->assessment === null ? null : ['id' => $assignment->assessment->ulid, 'name' => $assignment->assessment->name],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, CourseOffering $offering): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'file_id' => ['nullable', 'string', 'size:26'],
            'due_at' => ['required', 'date'],
            'max_marks' => ['required', 'numeric', 'min:1', 'max:1000'],
            'allow_late' => ['sometimes', 'boolean'],
            'assessment_id' => ['nullable', 'string', 'size:26'],
        ]);

        if (isset($validated['file_id'])) {
            $validated['file_id'] = StoredFile::query()->where('ulid', $validated['file_id'])->where('uploaded_by', $this->user($request)->id)->value('id')
                ?? throw new BusinessRuleException('Upload the file first.', 'FILE_INVALID', 422);
        }

        if (isset($validated['assessment_id'])) {
            $validated['assessment_id'] = Assessment::query()->where('ulid', $validated['assessment_id'])->whereHas('group', fn ($query) => $query->where('course_offering_id', $offering->id))->value('id')
                ?? throw new BusinessRuleException('Pick an assessment of this course.', 'ASSESSMENT_INVALID', 422);
        }

        return $validated;
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

    private function offering(string $ulid): CourseOffering
    {
        return CourseOffering::query()->with('course')->where('ulid', $ulid)->firstOrFail();
    }

    private function student(Request $request): Student
    {
        return Student::query()->where('user_id', $this->user($request)->id)->first() ?? throw new BusinessRuleException('You do not have a student record in this institution.', 'NOT_A_STUDENT', 404);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
