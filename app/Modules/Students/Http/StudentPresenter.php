<?php

namespace App\Modules\Students\Http;

use App\Modules\Institution\Http\Resources\AcademicResources;
use App\Modules\Students\Models\ProfileChangeRequest;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Models\StudentDocument;
use App\Modules\Students\Models\StudentGuardian;
use App\Modules\Students\Models\StudentStatusHistory;

/**
 * JSON shapes for student records.
 */
final class StudentPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(Student $student): array
    {
        return [
            'id' => $student->ulid,
            'user_id' => $student->user->ulid,
            'register_no' => $student->register_no,
            'roll_no' => $student->roll_no,
            'name' => $student->user->name,
            'email' => $student->user->email,
            'mobile' => $student->user->mobile,
            'programme' => AcademicResources::programme($student->programme),
            'department' => AcademicResources::department($student->programme->department),
            'batch' => $student->batch === null ? null : ['id' => $student->batch->ulid, 'name' => $student->batch->name],
            'section' => $student->section === null ? null : ['id' => $student->section->ulid, 'name' => $student->section->name],
            'current_term_number' => $student->current_term_number,
            'admission_type' => $student->admission_type->value,
            'category' => $student->category,
            'status' => $student->status->value,
            'status_label' => $student->status->label(),
            'admitted_on' => $student->admitted_on->toDateString(),
            'photo_file_id' => $student->photo?->ulid,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Student $student, bool $withIdentity = false): array
    {
        return array_merge(self::summary($student), [
            'personal' => [
                'date_of_birth' => $student->date_of_birth?->toDateString(),
                'gender' => $student->gender,
                'blood_group' => $student->blood_group,
                'nationality' => $student->nationality,
                'religion' => $student->religion,
                'mother_tongue' => $student->mother_tongue,
                'address' => $student->address,
                'permanent_address' => $student->permanent_address,
                'id_proof_type' => $student->id_proof_type,
                'id_proof_number' => $withIdentity ? $student->id_proof_number : $student->maskedIdProof(),
            ],
            'application_no' => $student->application?->application_no,
            'left_on' => $student->left_on?->toDateString(),
            'exit_reason' => $student->exit_reason,
            'guardians' => $student->guardians->map(fn (StudentGuardian $guardian): array => [
                'id' => $guardian->ulid,
                'relation' => $guardian->relation->value,
                'name' => $guardian->name,
                'phone' => $guardian->phone,
                'email' => $guardian->email,
                'occupation' => $guardian->occupation,
                'annual_income' => $guardian->annual_income !== null ? (float) $guardian->annual_income : null,
                'is_primary' => $guardian->is_primary,
            ])->values()->all(),
            'documents' => $student->documents->map(fn (StudentDocument $document): array => self::document($document))->values()->all(),
            'status_history' => $student->statusHistory->map(fn (StudentStatusHistory $entry): array => [
                'from' => $entry->from_status?->value,
                'to' => $entry->to_status->value,
                'effective_on' => $entry->effective_on->toDateString(),
                'reason' => $entry->reason,
                'by' => $entry->changer?->name,
            ])->values()->all(),
            'change_requests' => $student->changeRequests->map(fn (ProfileChangeRequest $request): array => self::changeRequest($request, false))->values()->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function document(StudentDocument $document): array
    {
        return [
            'id' => $document->ulid,
            'document_type' => $document->document_type,
            'file_id' => $document->file->ulid,
            'file_name' => $document->file->original_name,
            'status' => $document->status->value,
            'remarks' => $document->remarks,
            'reviewed_at' => $document->reviewed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function changeRequest(ProfileChangeRequest $request, bool $withStudent = true): array
    {
        $changes = $request->changes;

        if (isset($changes['id_proof_number'])) {
            $changes['id_proof_number'] = str_repeat('•', max(0, mb_strlen((string) $changes['id_proof_number']) - 4)).mb_substr((string) $changes['id_proof_number'], -4);
        }

        return array_filter([
            'id' => $request->ulid,
            'student' => $withStudent ? ['id' => $request->student->ulid, 'name' => $request->student->user->name, 'register_no' => $request->student->register_no] : null,
            'changes' => $changes,
            'reason' => $request->reason,
            'status' => $request->status->value,
            'remarks' => $request->remarks,
            'reviewed_by' => $request->reviewer?->name,
            'reviewed_at' => $request->reviewed_at?->toIso8601String(),
            'created_at' => $request->created_at?->toIso8601String(),
        ], fn ($value, $key): bool => $key !== 'student' || $value !== null, ARRAY_FILTER_USE_BOTH);
    }
}
