<?php

namespace App\Shared\Documents;

use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Public check of a printed document by its verification code (no sign-in).
 *
 * @tags Documents
 */
class VerifyDocumentController extends Controller
{
    public function __invoke(string $code): JsonResponse
    {
        $row = DB::table('document_verifications')->join('institutions', 'institutions.id', '=', 'document_verifications.institution_id')
            ->where('document_verifications.code', strtoupper($code))
            ->first(['document_verifications.*', 'institutions.name as institution']);

        abort_if($row === null, 404, 'No document has this code.');

        return ApiResponse::data([
            'code' => $row->code,
            'type' => $row->type,
            'title' => $row->title,
            'issued_to' => $row->issued_to,
            'institution' => $row->institution,
            'issued_at' => Carbon::parse($row->issued_at)->toIso8601String(),
            'summary' => json_decode((string) $row->summary, true) ?: [],
        ]);
    }
}
