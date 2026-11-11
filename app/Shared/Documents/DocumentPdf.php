<?php

namespace App\Shared\Documents;

use App\Shared\Tenancy\CurrentInstitution;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders an official document as a PDF (dompdf, no external service) and records a verification code
 * printed on it. Re-downloading the same document keeps the same code.
 */
class DocumentPdf
{
    public function __construct(private CurrentInstitution $institution) {}

    /**
     * @param  string  $type  receipt|payslip|hall_ticket|grade_card
     * @param  string  $reference  the record's id (ULID), unique per type
     * @param  array<string, mixed>  $data  passed to the Blade view
     * @param  array<string, string|int|float|null>  $summary  shown on the public verify page (no sensitive data)
     */
    public function download(string $type, string $reference, string $title, string $issuedTo, string $view, array $data, array $summary, string $filename): Response
    {
        $code = $this->verificationCode($type, $reference, $title, $issuedTo, $summary);
        $institution = $this->institution->require();

        $pdf = Pdf::loadView($view, [
            ...$data,
            'institution' => $institution,
            'documentTitle' => $title,
            'verificationCode' => $code,
            'verifyUrl' => rtrim((string) config('campusos.frontend_url'), '/').'/verify/'.$code,
        ])->setPaper('a4')->setOption('isFontSubsettingEnabled', true);

        return $pdf->download(Str::slug($filename).'.pdf');
    }

    /**
     * @param  array<string, string|int|float|null>  $summary
     */
    private function verificationCode(string $type, string $reference, string $title, string $issuedTo, array $summary): string
    {
        $institutionId = $this->institution->require()->id;
        $existing = DB::table('document_verifications')->where('institution_id', $institutionId)->where('type', $type)->where('reference', $reference)->first();

        if ($existing !== null) {
            DB::table('document_verifications')->where('id', $existing->id)->update(['title' => $title, 'issued_to' => $issuedTo, 'summary' => json_encode($summary), 'updated_at' => now()]);

            return (string) $existing->code;
        }

        do {
            $code = strtoupper(Str::random(4).'-'.Str::random(4));
        } while (DB::table('document_verifications')->where('code', $code)->exists());

        DB::table('document_verifications')->insert([
            'code' => $code, 'institution_id' => $institutionId, 'type' => $type, 'reference' => $reference, 'title' => $title,
            'issued_to' => $issuedTo, 'summary' => json_encode($summary), 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $code;
    }
}
