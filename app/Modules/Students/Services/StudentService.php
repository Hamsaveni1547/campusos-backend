<?php

namespace App\Modules\Students\Services;

use App\Models\User;
use App\Modules\Admissions\Models\Application;
use App\Modules\Identity\Actions\Roles\AssignRole;
use App\Modules\Identity\Actions\Roles\RevokeRoleAssignment;
use App\Modules\Identity\Actions\Users\CreateUser;
use App\Modules\Identity\Authorization\SystemRole;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Institution\Models\Batch;
use App\Modules\Institution\Models\Programme;
use App\Modules\Institution\Models\Section;
use App\Modules\Students\Enums\ReviewStatus;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Models\ProfileChangeRequest;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Models\StudentDocument;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Files\StoredFile;
use App\Shared\Numbering\NumberGenerator;
use App\Shared\Settings\SettingsRepository;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Student records: creation (manual, import, admission), edits, placement,
 * enrollment status, guardians, documents and profile change requests.
 */
class StudentService
{
    /** Fields a student may change directly. */
    public const SELF_EDITABLE = ['address', 'permanent_address', 'blood_group', 'mother_tongue', 'photo_file_id'];

    /** Fields a student may only change through an approved request. */
    public const REQUESTABLE = ['name', 'date_of_birth', 'gender', 'category', 'nationality', 'religion', 'id_proof_type', 'id_proof_number'];

    public function __construct(
        private CurrentInstitution $currentInstitution,
        private CreateUser $createUser,
        private AssignRole $assignRole,
        private RevokeRoleAssignment $revokeRole,
        private NumberGenerator $numbers,
        private SettingsRepository $settings,
    ) {}

    /**
     * @param  array{name: string, email: string, mobile?: string|null}  $account
     * @param  array<string, mixed>  $data  student fields; programme_id/batch_id/section_id already resolved
     * @param  list<array<string, mixed>>  $guardians
     */
    public function create(array $account, array $data, User $actor, bool $sendInvite = true, array $guardians = []): Student
    {
        $institution = $this->currentInstitution->require();
        $email = mb_strtolower(trim($account['email']));
        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null && Student::query()->where('user_id', $existing->id)->exists()) {
            throw new BusinessRuleException('This person already has a student record in this institution.', 'STUDENT_EXISTS', 409);
        }

        if (filled($data['register_no'] ?? null) && Student::query()->where('register_no', $data['register_no'])->exists()) {
            throw new BusinessRuleException('This register number is already in use.', 'REGISTER_NO_TAKEN', 409);
        }

        return DB::transaction(function () use ($account, $data, $actor, $sendInvite, $guardians, $institution, $existing): Student {
            if ($existing !== null && $existing->belongsToInstitution($institution->id)) {
                $user = $existing;
                $this->giveStudentRole($user, $actor);
            } else {
                $user = $this->createUser->handle(
                    ['name' => $account['name'], 'email' => $account['email'], 'mobile' => $account['mobile'] ?? null],
                    $actor,
                    [$this->studentRole()],
                    $sendInvite,
                )['user'];
            }

            /** @var Programme $programme */
            $programme = Programme::query()->findOrFail($data['programme_id']);
            $data['register_no'] = filled($data['register_no'] ?? null) ? $data['register_no'] : $this->nextRegisterNo($programme);
            $data['batch_id'] ??= $this->batchFor($programme, Carbon::parse($data['admitted_on'] ?? now())->year)?->id;
            $this->assertPlacement($programme->id, $data['batch_id'] ?? null, $data['section_id'] ?? null);

            $student = Student::query()->create(array_merge($data, ['user_id' => $user->id]));
            $this->recordStatus($student, null, StudentStatus::Active, $student->admitted_on, 'Enrolled', $actor);
            $this->syncGuardians($student, $guardians);

            return $student;
        });
    }

    /**
     * Admission confirmed: create the student from the application, keeping its documents.
     */
    public function createFromApplication(Application $application, User $actor): Student
    {
        $application->loadMissing(['seat.programme', 'documents', 'user']);
        $personal = $application->personal ?? [];
        $guardian = $application->guardian ?? [];
        $programme = $application->seat->programme;

        $student = $this->create(
            ['name' => $application->user->name, 'email' => $application->user->email, 'mobile' => $application->user->mobile],
            [
                'register_no' => $application->register_no,
                'programme_id' => $programme->id,
                'application_id' => $application->id,
                'admission_type' => $application->seat->quota,
                'category' => $application->category,
                'admitted_on' => now()->toDateString(),
                'date_of_birth' => $personal['date_of_birth'] ?? null,
                'gender' => $personal['gender'] ?? null,
                'address' => $personal['address'] ?? null,
            ],
            $actor,
            false,
            filled($guardian['name'] ?? null) ? [['relation' => 'guardian', 'name' => $guardian['name'], 'phone' => $guardian['phone'] ?? null, 'is_primary' => true]] : [],
        );

        foreach ($application->documents as $document) {
            StudentDocument::query()->create([
                'student_id' => $student->id,
                'document_type' => $document->document_type,
                'file_id' => $document->file_id,
                'status' => ReviewStatus::Approved,
                'remarks' => 'Verified during admission',
                'reviewed_by' => $document->verified_by,
                'reviewed_at' => $document->verified_at,
            ]);
        }

        return $student;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $account  name, mobile
     */
    public function update(Student $student, array $data, array $account = []): Student
    {
        if (array_key_exists('register_no', $data) && $data['register_no'] !== $student->register_no) {
            throw new BusinessRuleException('The register number cannot be changed once issued.', 'REGISTER_NO_LOCKED', 409);
        }

        DB::transaction(function () use ($student, $data, $account): void {
            $student->fill($data)->save();

            if ($account !== []) {
                $student->user->fill(array_intersect_key($account, array_flip(['name', 'mobile'])))->save();
            }
        });

        return $student;
    }

    public function place(Student $student, ?int $batchId, ?int $sectionId, ?string $rollNo, ?int $termNumber = null): Student
    {
        $this->assertPlacement($student->programme_id, $batchId, $sectionId);

        if ($rollNo !== null && $rollNo !== '' && Student::query()->where('section_id', $sectionId)->where('roll_no', $rollNo)->whereKeyNot($student->id)->exists()) {
            throw new BusinessRuleException('This roll number is already used in the section.', 'ROLL_NO_TAKEN', 409);
        }

        $student->fill(['batch_id' => $batchId, 'section_id' => $sectionId, 'roll_no' => $rollNo ?: null]);

        if ($termNumber !== null) {
            $student->current_term_number = $termNumber;
        }

        $student->save();

        return $student;
    }

    public function changeStatus(Student $student, StudentStatus $to, Carbon $effectiveOn, ?string $reason, User $actor): Student
    {
        if (! $student->status->canMoveTo($to)) {
            throw new BusinessRuleException('A student who is "'.$student->status->label().'" cannot become "'.$to->label().'".', 'INVALID_STATUS', 409);
        }

        if ($to->isFinal() && blank($reason)) {
            throw new BusinessRuleException('Give a reason for this change.', 'REASON_REQUIRED', 422);
        }

        return DB::transaction(function () use ($student, $to, $effectiveOn, $reason, $actor): Student {
            $from = $student->status;
            $student->status = $to;

            if ($to->isFinal()) {
                $student->left_on = $effectiveOn;
                $student->exit_reason = $reason;
                $this->removeStudentRole($student->user, $actor, $to->label());
            }

            $student->save();
            $this->recordStatus($student, $from, $to, $effectiveOn, $reason, $actor);

            return $student;
        });
    }

    /**
     * Replace the guardian list.
     *
     * @param  list<array<string, mixed>>  $guardians
     */
    public function syncGuardians(Student $student, array $guardians): void
    {
        $student->guardians()->delete();
        $hasPrimary = collect($guardians)->contains(fn (array $guardian): bool => (bool) ($guardian['is_primary'] ?? false));

        foreach ($guardians as $index => $guardian) {
            $student->guardians()->create(array_merge($guardian, [
                'institution_id' => $student->institution_id,
                'is_primary' => $hasPrimary ? (bool) ($guardian['is_primary'] ?? false) : $index === 0,
            ]));
        }
    }

    /**
     * Staff uploads are approved straight away; student uploads wait for verification.
     */
    public function attachDocument(Student $student, string $type, StoredFile $file, User $actor, bool $byStaff): StudentDocument
    {
        return StudentDocument::query()->updateOrCreate(
            ['student_id' => $student->id, 'document_type' => $type],
            [
                'file_id' => $file->id,
                'status' => $byStaff ? ReviewStatus::Approved : ReviewStatus::Pending,
                'remarks' => null,
                'reviewed_by' => $byStaff ? $actor->id : null,
                'reviewed_at' => $byStaff ? now() : null,
            ],
        );
    }

    public function reviewDocument(StudentDocument $document, bool $approved, ?string $remarks, User $actor): StudentDocument
    {
        if (! $approved && blank($remarks)) {
            throw new BusinessRuleException('Say why the document is rejected.', 'REMARKS_REQUIRED', 422);
        }

        $document->update([
            'status' => $approved ? ReviewStatus::Approved : ReviewStatus::Rejected,
            'remarks' => $remarks,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
        ]);

        return $document;
    }

    /**
     * @param  array<string, mixed>  $changes  only REQUESTABLE keys
     */
    public function requestChange(Student $student, array $changes, ?string $reason): ProfileChangeRequest
    {
        $changes = array_intersect_key($changes, array_flip(self::REQUESTABLE));

        if ($changes === []) {
            throw new BusinessRuleException('Say what should change.', 'NO_CHANGES', 422);
        }

        if ($student->changeRequests()->where('status', ReviewStatus::Pending)->exists()) {
            throw new BusinessRuleException('You already have a request waiting for review.', 'REQUEST_PENDING', 409);
        }

        return $student->changeRequests()->create(['changes' => $changes, 'reason' => $reason, 'institution_id' => $student->institution_id]);
    }

    public function reviewChange(ProfileChangeRequest $request, bool $approved, ?string $remarks, User $actor): ProfileChangeRequest
    {
        if ($request->status !== ReviewStatus::Pending) {
            throw new BusinessRuleException('This request has already been reviewed.', 'ALREADY_REVIEWED', 409);
        }

        if (! $approved && blank($remarks)) {
            throw new BusinessRuleException('Say why the request is rejected.', 'REMARKS_REQUIRED', 422);
        }

        DB::transaction(function () use ($request, $approved, $remarks, $actor): void {
            if ($approved) {
                $changes = $request->changes;
                $this->update($request->student, array_diff_key($changes, ['name' => true]), isset($changes['name']) ? ['name' => $changes['name']] : []);
            }

            $request->forceFill([
                'status' => $approved ? ReviewStatus::Approved : ReviewStatus::Rejected,
                'remarks' => $remarks,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ])->save();
        });

        return $request;
    }

    // ----- internals -------------------------------------------------------

    private function nextRegisterNo(Programme $programme): string
    {
        $institution = $this->currentInstitution->require();

        return $this->numbers->next(
            'register_no',
            (string) $this->settings->get('admissions', 'register_no_format'),
            $institution->id,
            scope: $programme->code.'-'.now()->format('Y'),
            tokens: ['INST' => $institution->code, 'PROG' => preg_replace('/[^A-Z0-9]/', '', strtoupper($programme->code))],
        );
    }

    private function batchFor(Programme $programme, int $year): ?Batch
    {
        return Batch::query()->where('programme_id', $programme->id)->where('admission_year', $year)->first();
    }

    private function assertPlacement(int $programmeId, ?int $batchId, ?int $sectionId): void
    {
        if ($batchId !== null && ! Batch::query()->whereKey($batchId)->where('programme_id', $programmeId)->exists()) {
            throw new BusinessRuleException('The batch must belong to the student\'s programme.', 'BATCH_MISMATCH', 422);
        }

        if ($sectionId !== null && ($batchId === null || ! Section::query()->whereKey($sectionId)->where('batch_id', $batchId)->exists())) {
            throw new BusinessRuleException('The section must belong to the chosen batch.', 'SECTION_MISMATCH', 422);
        }
    }

    private function recordStatus(Student $student, ?StudentStatus $from, StudentStatus $to, Carbon $effectiveOn, ?string $reason, ?User $actor): void
    {
        $student->statusHistory()->create([
            'institution_id' => $student->institution_id,
            'from_status' => $from,
            'to_status' => $to,
            'effective_on' => $effectiveOn,
            'reason' => $reason,
            'changed_by' => $actor?->id,
        ]);
    }

    private function studentRole(): Role
    {
        return Role::query()->whereNull('institution_id')->where('name', SystemRole::Student->value)->firstOrFail();
    }

    private function giveStudentRole(User $user, User $actor): void
    {
        $role = $this->studentRole();

        if (! RoleAssignment::query()->effective()->where('user_id', $user->id)->where('role_id', $role->id)->exists()) {
            $this->assignRole->handle($user, $role, $actor, reason: 'Student record created');
        }
    }

    private function removeStudentRole(User $user, User $actor, string $reason): void
    {
        RoleAssignment::query()->effective()->where('user_id', $user->id)->where('role_id', $this->studentRole()->id)
            ->each(fn (RoleAssignment $assignment) => $this->revokeRole->handle($assignment, $actor, 'Student status: '.$reason));
    }
}
