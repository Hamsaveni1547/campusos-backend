<?php

namespace App\Modules\Students\Http\Controllers;

use App\Models\User;
use App\Modules\Admissions\Enums\Quota;
use App\Modules\Institution\Models\Batch;
use App\Modules\Institution\Models\Programme;
use App\Modules\Institution\Models\Section;
use App\Modules\Students\Authorization\StudentsPermissions;
use App\Modules\Students\Enums\GuardianRelation;
use App\Modules\Students\Enums\ReviewStatus;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Http\StudentPresenter;
use App\Modules\Students\Models\ProfileChangeRequest;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Models\StudentDocument;
use App\Modules\Students\Services\StudentService;
use App\Modules\Students\Support\StudentScope;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Files\StoredFile;
use App\Shared\Http\ApiResponse;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * @tags Students
 */
class StudentController extends Controller
{
    private const RELATIONS = ['user', 'programme.department', 'batch', 'section', 'photo', 'application', 'guardians', 'documents.file', 'statusHistory.changer', 'changeRequests'];

    public function __construct(
        private StudentService $students,
        private CurrentInstitution $currentInstitution,
    ) {}

    /**
     * Students. Filters: `filter[search]` (name, email, register/roll no), `filter[programme]`, `filter[department]`,
     * `filter[batch]`, `filter[section]`, `filter[status]` (default: enrolled), `filter[admission_type]`, `filter[category]`.
     * Sort: `sort=name|register_no|roll_no|-admitted_on`. Heads of department and faculty see their departments only.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(StudentsPermissions::RecordsView);
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:200']]);

        $status = $request->input('filter.status');
        $sort = (string) $request->input('sort', 'register_no');

        $query = StudentScope::apply(Student::query(), $this->user($request), $this->institutionId())
            ->with(['user', 'programme.department', 'batch', 'section', 'photo'])
            ->when($status === null || $status === '', fn ($query) => $query->whereNotIn('status', [StudentStatus::Graduated, StudentStatus::DroppedOut, StudentStatus::TransferredOut]))
            ->when($status !== null && $status !== '' && $status !== 'all', fn ($query) => $query->whereIn('status', explode(',', (string) $status)))
            ->when($request->input('filter.programme'), fn ($query, $id) => $query->whereHas('programme', fn ($query) => $query->where('ulid', $id)))
            ->when($request->input('filter.department'), fn ($query, $id) => $query->whereHas('programme.department', fn ($query) => $query->where('ulid', $id)))
            ->when($request->input('filter.batch'), fn ($query, $id) => $query->whereHas('batch', fn ($query) => $query->where('ulid', $id)))
            ->when($request->input('filter.section'), fn ($query, $id) => $query->whereHas('section', fn ($query) => $query->where('ulid', $id)))
            ->when($request->input('filter.admission_type'), fn ($query, $type) => $query->where('admission_type', $type))
            ->when($request->input('filter.category'), fn ($query, $category) => $query->where('category', $category))
            ->when($request->input('filter.search'), fn ($query, $search) => $query->where(fn ($query) => $query
                ->where('register_no', 'like', "%{$search}%")
                ->orWhere('roll_no', $search)
                ->orWhereHas('user', fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))));

        match (ltrim($sort, '-')) {
            'name' => $query->orderBy(User::query()->select('name')->whereColumn('users.id', 'students.user_id'), str_starts_with($sort, '-') ? 'desc' : 'asc'),
            'roll_no', 'admitted_on' => $query->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc'),
            default => $query->orderBy('register_no', str_starts_with($sort, '-') ? 'desc' : 'asc'),
        };

        return ApiResponse::paginated($query->paginate(min((int) $request->integer('per_page', 25), 200)), fn (Student $student): array => StudentPresenter::summary($student));
    }

    public function show(Request $request, Student $student): JsonResponse
    {
        Gate::authorize(StudentsPermissions::RecordsView);
        $this->assertVisible($request, $student);

        return ApiResponse::data(StudentPresenter::detail($student->load(self::RELATIONS), withIdentity: $this->user($request)->can(StudentsPermissions::RecordsUpdate)));
    }

    /**
     * Add a student who did not come through admissions (e.g. existing students, lateral entry).
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize(StudentsPermissions::RecordsCreate);

        $validated = $request->validate(array_merge([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'mobile' => ['nullable', 'regex:/^\+?[0-9]{10,15}$/'],
            'send_invite' => ['sometimes', 'boolean'],
            'register_no' => ['nullable', 'string', 'max:40', 'alpha_dash'],
            'programme_id' => ['required', 'string', 'size:26'],
            'admission_type' => ['required', Rule::enum(Quota::class)],
            'admitted_on' => ['required', 'date', 'before_or_equal:today'],
            'guardians' => ['sometimes', 'array', 'max:4'],
        ], $this->profileRules(), $this->placementRules(), $this->guardianRules()));

        $data = $this->profileData($validated) + $this->placementData($validated) + [
            'register_no' => $validated['register_no'] ?? null,
            'programme_id' => $this->programme($validated['programme_id'])->id,
            'admission_type' => $validated['admission_type'],
            'admitted_on' => $validated['admitted_on'],
        ];

        $student = $this->students->create(
            ['name' => $validated['name'], 'email' => $validated['email'], 'mobile' => $validated['mobile'] ?? null],
            $data,
            $this->user($request),
            $request->boolean('send_invite', true),
            $validated['guardians'] ?? [],
        );

        return ApiResponse::data(StudentPresenter::detail($student->load(self::RELATIONS), withIdentity: true), 201);
    }

    public function update(Request $request, Student $student): JsonResponse
    {
        Gate::authorize(StudentsPermissions::RecordsUpdate);
        $this->assertVisible($request, $student);

        $validated = $request->validate(array_merge([
            'name' => ['sometimes', 'string', 'max:150'],
            'mobile' => ['sometimes', 'nullable', 'regex:/^\+?[0-9]{10,15}$/'],
            'admission_type' => ['sometimes', Rule::enum(Quota::class)],
            'register_no' => ['sometimes', 'string'],
        ], $this->profileRules()));

        $this->students->update(
            $student,
            $this->profileData($validated) + array_intersect_key($validated, array_flip(['admission_type', 'register_no'])),
            array_intersect_key($validated, array_flip(['name', 'mobile'])),
        );

        return $this->show($request, $student->refresh());
    }

    /**
     * Batch, section, roll number and current term.
     */
    public function place(Request $request, Student $student): JsonResponse
    {
        Gate::authorize(StudentsPermissions::RecordsUpdate);
        $this->assertVisible($request, $student);
        $validated = $request->validate(array_merge($this->placementRules(), ['current_term_number' => ['sometimes', 'integer', 'min:1', 'max:21']]));
        $placement = $this->placementData($validated);

        $this->students->place($student, $placement['batch_id'] ?? null, $placement['section_id'] ?? null, $validated['roll_no'] ?? null, $validated['current_term_number'] ?? null);

        return $this->show($request, $student->refresh());
    }

    public function changeStatus(Request $request, Student $student): JsonResponse
    {
        Gate::authorize(StudentsPermissions::StatusChange);
        $this->assertVisible($request, $student);
        $validated = $request->validate([
            'status' => ['required', Rule::enum(StudentStatus::class)],
            'effective_on' => ['sometimes', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->students->changeStatus($student, StudentStatus::from($validated['status']), Carbon::parse($validated['effective_on'] ?? now()), $validated['reason'] ?? null, $this->user($request));

        return $this->show($request, $student->refresh());
    }

    /**
     * Replace the guardian list.
     */
    public function guardians(Request $request, Student $student): JsonResponse
    {
        Gate::authorize(StudentsPermissions::RecordsUpdate);
        $this->assertVisible($request, $student);
        $validated = $request->validate(array_merge(['guardians' => ['present', 'array', 'max:4']], $this->guardianRules()));

        $this->students->syncGuardians($student, $validated['guardians']);

        return $this->show($request, $student->refresh());
    }

    /**
     * Staff upload (approved straight away). The file is uploaded first with purpose "document".
     */
    public function attachDocument(Request $request, Student $student): JsonResponse
    {
        Gate::authorize(StudentsPermissions::RecordsUpdate);
        $this->assertVisible($request, $student);
        $validated = $request->validate(['document_type' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/'], 'file_id' => ['required', 'string', 'size:26']]);

        $this->students->attachDocument($student, $validated['document_type'], $this->ownFile($request, $validated['file_id']), $this->user($request), byStaff: true);

        return $this->show($request, $student->refresh());
    }

    public function reviewDocument(Request $request, Student $student, string $document): JsonResponse
    {
        Gate::authorize(StudentsPermissions::DocumentsVerify);
        $this->assertVisible($request, $student);
        $validated = $request->validate(['approved' => ['required', 'boolean'], 'remarks' => ['nullable', 'string', 'max:500']]);

        $model = StudentDocument::query()->where('student_id', $student->id)->where('ulid', $document)->firstOrFail();
        $this->students->reviewDocument($model, (bool) $validated['approved'], $validated['remarks'] ?? null, $this->user($request));

        return $this->show($request, $student->refresh());
    }

    /**
     * Profile correction requests. Filter: `filter[status]` (default pending).
     */
    public function changeRequests(Request $request): JsonResponse
    {
        Gate::authorize(StudentsPermissions::ChangesApprove);

        $requests = ProfileChangeRequest::query()
            ->with(['student.user', 'reviewer'])
            ->whereHas('student', fn ($query) => StudentScope::apply($query, $this->user($request), $this->institutionId()))
            ->where('status', $request->input('filter.status', ReviewStatus::Pending->value))
            ->latest('id')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return ApiResponse::paginated($requests, fn (ProfileChangeRequest $changeRequest): array => StudentPresenter::changeRequest($changeRequest));
    }

    public function reviewChangeRequest(Request $request, string $changeRequest): JsonResponse
    {
        Gate::authorize(StudentsPermissions::ChangesApprove);
        $validated = $request->validate(['approved' => ['required', 'boolean'], 'remarks' => ['nullable', 'string', 'max:500']]);

        $model = ProfileChangeRequest::query()->with('student.user')->where('ulid', $changeRequest)->firstOrFail();
        $this->assertVisible($request, $model->student);
        $this->students->reviewChange($model, (bool) $validated['approved'], $validated['remarks'] ?? null, $this->user($request));

        return ApiResponse::data(StudentPresenter::changeRequest($model->refresh()->load(['student.user', 'reviewer'])));
    }

    /**
     * Counts by status and programme (dashboard).
     */
    public function summary(Request $request): JsonResponse
    {
        Gate::authorize(StudentsPermissions::RecordsView);

        $base = fn () => StudentScope::apply(Student::query(), $this->user($request), $this->institutionId());

        return ApiResponse::data([
            'by_status' => $base()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'by_programme' => $base()->whereNotIn('students.status', [StudentStatus::Graduated, StudentStatus::DroppedOut, StudentStatus::TransferredOut])
                ->join('programmes', 'programmes.id', '=', 'students.programme_id')
                ->selectRaw('programmes.name as programme, count(*) as total')->groupBy('programmes.name')->orderBy('programmes.name')->pluck('total', 'programme'),
        ]);
    }

    // ----- helpers -----------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function profileRules(): array
    {
        return [
            'category' => ['sometimes', 'nullable', 'string', 'max:30'],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:-14 years'],
            'gender' => ['sometimes', 'nullable', 'in:female,male,other,prefer_not_to_say'],
            'blood_group' => ['sometimes', 'nullable', 'in:A+,A-,B+,B-,AB+,AB-,O+,O-'],
            'nationality' => ['sometimes', 'nullable', 'string', 'max:50'],
            'religion' => ['sometimes', 'nullable', 'string', 'max:50'],
            'mother_tongue' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'permanent_address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'id_proof_type' => ['sometimes', 'nullable', 'in:aadhaar,passport,voter_id,driving_licence,other'],
            'id_proof_number' => ['sometimes', 'nullable', 'string', 'max:30'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function profileData(array $validated): array
    {
        return array_intersect_key($validated, $this->profileRules());
    }

    /**
     * @return array<string, mixed>
     */
    private function placementRules(): array
    {
        return [
            'batch_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'section_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'roll_no' => ['sometimes', 'nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function placementData(array $validated): array
    {
        $data = [];

        if (array_key_exists('batch_id', $validated)) {
            $data['batch_id'] = $validated['batch_id'] !== null ? Batch::query()->where('ulid', $validated['batch_id'])->firstOrFail()->id : null;
        }

        if (array_key_exists('section_id', $validated)) {
            $data['section_id'] = $validated['section_id'] !== null ? Section::query()->where('ulid', $validated['section_id'])->firstOrFail()->id : null;
        }

        if (array_key_exists('roll_no', $validated)) {
            $data['roll_no'] = $validated['roll_no'];
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function guardianRules(): array
    {
        return [
            'guardians.*.relation' => ['required', Rule::enum(GuardianRelation::class)],
            'guardians.*.name' => ['required', 'string', 'max:150'],
            'guardians.*.phone' => ['nullable', 'regex:/^\+?[0-9]{10,15}$/'],
            'guardians.*.email' => ['nullable', 'email', 'max:255'],
            'guardians.*.occupation' => ['nullable', 'string', 'max:100'],
            'guardians.*.annual_income' => ['nullable', 'numeric', 'min:0'],
            'guardians.*.is_primary' => ['sometimes', 'boolean'],
        ];
    }

    private function programme(string $ulid): Programme
    {
        $programme = Programme::query()->where('ulid', $ulid)->first();

        if ($programme === null) {
            throw new BusinessRuleException('Choose a programme.', 'PROGRAMME_INVALID', 422);
        }

        return $programme;
    }

    private function ownFile(Request $request, string $ulid): StoredFile
    {
        $file = StoredFile::query()->where('ulid', $ulid)->where('uploaded_by', $this->user($request)->id)->whereIn('purpose', ['document', 'avatar'])->first();

        if ($file === null) {
            throw new BusinessRuleException('Upload the file with purpose "document" first.', 'DOCUMENT_FILE_INVALID', 422);
        }

        return $file;
    }

    private function assertVisible(Request $request, Student $student): void
    {
        $departments = StudentScope::departmentsFor($this->user($request), $this->institutionId());

        if ($departments !== null && ! in_array($student->programme->department_id, $departments, true)) {
            abort(404);
        }
    }

    private function institutionId(): int
    {
        return $this->currentInstitution->require()->id;
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
