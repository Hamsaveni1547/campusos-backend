<?php

namespace App\Modules\Students\Http\Controllers;

use App\Models\User;
use App\Modules\Students\Enums\GuardianRelation;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Http\StudentPresenter;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Services\StudentService;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Files\StoredFile;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * The signed-in student's own record.
 *
 * @tags Students: self-service
 */
class MyStudentController extends Controller
{
    public const ID_PROOF_TYPES = ['aadhaar' => 'Aadhaar', 'passport' => 'Passport', 'voter_id' => 'Voter ID', 'driving_licence' => 'Driving licence', 'other' => 'Other'];

    public const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    private const RELATIONS = ['user', 'programme.department', 'batch', 'section', 'photo', 'application', 'guardians', 'documents.file', 'statusHistory.changer', 'changeRequests'];

    public function __construct(private StudentService $students) {}

    /**
     * Option lists with labels so clients never hard-code them.
     */
    public function options(): JsonResponse
    {
        $list = fn (array $items): array => collect($items)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all();

        return ApiResponse::data([
            'statuses' => collect(StudentStatus::cases())->map(fn (StudentStatus $status): array => ['value' => $status->value, 'label' => $status->label(), 'final' => $status->isFinal()])->values(),
            'guardian_relations' => collect(GuardianRelation::cases())->map(fn (GuardianRelation $relation): array => ['value' => $relation->value, 'label' => ucfirst($relation->value)])->values(),
            'id_proof_types' => $list(self::ID_PROOF_TYPES),
            'blood_groups' => $list(array_combine(self::BLOOD_GROUPS, self::BLOOD_GROUPS)),
            'genders' => $list(['female' => 'Female', 'male' => 'Male', 'other' => 'Other', 'prefer_not_to_say' => 'Prefer not to say']),
            'self_editable' => StudentService::SELF_EDITABLE,
            'requestable' => StudentService::REQUESTABLE,
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::data(array_merge(
            StudentPresenter::detail($this->student($request)->load(self::RELATIONS)),
            ['self_editable' => StudentService::SELF_EDITABLE, 'requestable' => StudentService::REQUESTABLE],
        ));
    }

    /**
     * Change contact and other self-editable details (address, blood group, mother tongue, photo).
     */
    public function update(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $validated = $request->validate([
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'permanent_address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'blood_group' => ['sometimes', 'nullable', Rule::in(MyStudentController::BLOOD_GROUPS)],
            'mother_tongue' => ['sometimes', 'nullable', 'string', 'max:50'],
            'photo_file_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ]);

        if (array_key_exists('photo_file_id', $validated) && $validated['photo_file_id'] !== null) {
            $validated['photo_file_id'] = $this->ownFile($request, $validated['photo_file_id'], ['avatar'])->id;
        }

        $this->students->update($student, $validated);

        return $this->show($request);
    }

    /**
     * Upload a document for verification (file uploaded first with purpose "document").
     */
    public function attachDocument(Request $request): JsonResponse
    {
        $validated = $request->validate(['document_type' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/'], 'file_id' => ['required', 'string', 'size:26']]);

        $this->students->attachDocument($this->student($request), $validated['document_type'], $this->ownFile($request, $validated['file_id'], ['document']), $this->user($request), byStaff: false);

        return $this->show($request);
    }

    /**
     * Ask the office to correct details the student cannot edit (name, date of birth, category…).
     */
    public function requestChange(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'changes' => ['required', 'array'],
            'changes.name' => ['sometimes', 'string', 'max:150'],
            'changes.date_of_birth' => ['sometimes', 'date', 'before:-14 years'],
            'changes.gender' => ['sometimes', 'in:female,male,other,prefer_not_to_say'],
            'changes.category' => ['sometimes', 'string', 'max:30'],
            'changes.nationality' => ['sometimes', 'string', 'max:50'],
            'changes.religion' => ['sometimes', 'string', 'max:50'],
            'changes.id_proof_type' => ['sometimes', Rule::in(array_keys(MyStudentController::ID_PROOF_TYPES))],
            'changes.id_proof_number' => ['sometimes', 'string', 'max:30'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->students->requestChange($this->student($request), $validated['changes'], $validated['reason']);

        return $this->show($request);
    }

    private function student(Request $request): Student
    {
        $student = Student::query()->where('user_id', $this->user($request)->id)->first();

        if ($student === null) {
            throw new BusinessRuleException('You do not have a student record in this institution.', 'NOT_A_STUDENT', 404);
        }

        return $student;
    }

    /**
     * @param  list<string>  $purposes
     */
    private function ownFile(Request $request, string $ulid, array $purposes): StoredFile
    {
        $file = StoredFile::query()->where('ulid', $ulid)->where('uploaded_by', $this->user($request)->id)->whereIn('purpose', $purposes)->first();

        if ($file === null) {
            throw new BusinessRuleException('Upload the file first.', 'FILE_INVALID', 422);
        }

        return $file;
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
