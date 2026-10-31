<?php

namespace App\Modules\Admissions\Http;

use App\Modules\Admissions\Models\AdmissionCycle;
use App\Modules\Admissions\Models\AdmissionSeat;
use App\Modules\Admissions\Models\Application;
use App\Modules\Admissions\Models\ApplicationDocument;
use App\Modules\Admissions\Models\ApplicationStatusHistory;
use App\Modules\Institution\Http\Resources\AcademicResources;

/**
 * JSON shapes for admissions.
 */
final class AdmissionPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function cycle(AdmissionCycle $cycle, bool $withSeats = false): array
    {
        $data = [
            'id' => $cycle->ulid,
            'name' => $cycle->name,
            'status' => $cycle->status->value,
            'is_accepting' => $cycle->isAcceptingApplications(),
            'academic_year' => AcademicResources::academicYear($cycle->academicYear),
            'opens_on' => $cycle->opens_on->toDateString(),
            'closes_on' => $cycle->closes_on->toDateString(),
            'application_fee' => (float) $cycle->application_fee,
            'offer_valid_days' => $cycle->offer_valid_days,
            'required_documents' => $cycle->requiredDocuments(),
            'instructions' => $cycle->instructions,
            'applications_count' => $cycle->applications_count ?? null,
        ];

        if ($withSeats) {
            $data['seats'] = $cycle->seats->map(fn (AdmissionSeat $seat): array => self::seat($seat))->values()->all();
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function seat(AdmissionSeat $seat): array
    {
        return [
            'id' => $seat->ulid,
            'programme' => AcademicResources::programme($seat->programme),
            'quota' => $seat->quota->value,
            'total' => $seat->total,
            'filled' => $seat->filled,
            'available' => $seat->available(),
            'min_percentage' => $seat->min_percentage !== null ? (float) $seat->min_percentage : null,
            'admission_fee' => (float) $seat->admission_fee,
            'needs_approval' => $seat->quota->needsApproval(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function application(Application $application, bool $detail = false): array
    {
        $data = [
            'id' => $application->ulid,
            'application_no' => $application->application_no,
            'status' => $application->status->value,
            'status_label' => $application->status->label(),
            'cycle' => ['id' => $application->cycle->ulid, 'name' => $application->cycle->name],
            'seat' => self::seat($application->seat),
            'applicant' => ['id' => $application->user->ulid, 'name' => $application->user->name, 'email' => $application->user->email, 'mobile' => $application->user->mobile],
            'category' => $application->category,
            'qualifying_percentage' => $application->qualifying_percentage !== null ? (float) $application->qualifying_percentage : null,
            'remarks' => $application->remarks,
            'can_edit' => $application->status->isEditableByApplicant(),
            'submitted_at' => $application->submitted_at?->toIso8601String(),
            'offered_at' => $application->offered_at?->toIso8601String(),
            'offer_expires_at' => $application->offer_expires_at?->toIso8601String(),
            'admitted_at' => $application->admitted_at?->toIso8601String(),
            'register_no' => $application->register_no,
            'created_at' => $application->created_at?->toIso8601String(),
        ];

        if ($detail) {
            $data += [
                'personal' => $application->personal ?? [],
                'guardian' => $application->guardian ?? [],
                'academic' => $application->academic ?? [],
                'entrance' => $application->entrance ?? [],
                'fee_amount' => $application->fee_amount !== null ? (float) $application->fee_amount : null,
                'fee_reference' => $application->fee_reference,
                'required_documents' => $application->cycle->requiredDocuments(),
                'documents' => $application->documents->map(fn (ApplicationDocument $document): array => self::document($document))->values()->all(),
                'history' => $application->history->map(fn (ApplicationStatusHistory $entry): array => [
                    'from' => $entry->from_status?->value,
                    'to' => $entry->to_status->value,
                    'remarks' => $entry->remarks,
                    'by' => $entry->changer?->name,
                    'at' => $entry->created_at?->toIso8601String(),
                ])->values()->all(),
            ];
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function document(ApplicationDocument $document): array
    {
        return [
            'id' => $document->ulid,
            'document_type' => $document->document_type,
            'file_id' => $document->file->ulid,
            'file_name' => $document->file->original_name,
            'status' => $document->status->value,
            'remarks' => $document->remarks,
            'verified_at' => $document->verified_at?->toIso8601String(),
        ];
    }
}
