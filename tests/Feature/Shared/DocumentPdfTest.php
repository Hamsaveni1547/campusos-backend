<?php

namespace Tests\Feature\Shared;

use App\Shared\Documents\DocumentPdf;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DocumentPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_carries_a_reusable_verification_code_that_anyone_can_check(): void
    {
        $institution = $this->institution();
        $hallTicket = ['cycle' => ['id' => 'c1', 'name' => 'Nov 2026'], 'student' => ['name' => 'Asha', 'register_no' => 'R1', 'programme' => 'BE CSE', 'photo_id' => null], 'exams' => [['code' => '21CS51', 'title' => 'DBMS', 'exam' => ['date' => '2026-11-20', 'starts_at' => '10:00', 'ends_at' => '13:00']]]];

        $download = fn () => app(CurrentInstitution::class)->runAs($institution, fn () => app(DocumentPdf::class)
            ->download('hall_ticket', 'c1:R1', 'Hall ticket — Nov 2026', 'Asha', 'pdf.hall-ticket', ['t' => $hallTicket], ['Register no.' => 'R1'], 'hall-ticket-R1'));

        $first = $download();
        $this->assertSame('application/pdf', $first->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $first->getContent());
        $download();

        $this->assertSame(1, DB::table('document_verifications')->count(), 'downloading again keeps the same code');
        $code = (string) DB::table('document_verifications')->value('code');

        $this->getJson('/api/v1/verify/'.strtolower($code))->assertOk()->assertJsonPath('data.issued_to', 'Asha')->assertJsonFragment(['Register no.' => 'R1']);
        $this->getJson('/api/v1/verify/NONE-0000')->assertNotFound();
    }
}
