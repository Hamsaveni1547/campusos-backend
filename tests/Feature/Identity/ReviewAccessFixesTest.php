<?php

namespace Tests\Feature\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ReviewAccessFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_who_manage_users_can_read_the_role_list_and_hr_sees_payroll(): void
    {
        $institution = $this->institution();
        $office = $this->member($institution, ['admin_staff']);
        $hr = $this->member($institution, ['hr_officer'], ['email' => 'hr@example.edu']);
        $librarian = $this->member($institution, ['librarian'], ['email' => 'lib@example.edu']);

        $this->api('GET', 'roles', $office)->assertOk();
        $this->api('GET', 'roles', $hr)->assertOk();
        $this->api('GET', 'roles', $librarian)->assertForbidden();
        $this->api('GET', 'payroll-runs', $hr)->assertOk();
    }

    public function test_office_documents_upload_as_documents(): void
    {
        $user = $this->member($this->institution(), ['faculty']);

        $docx = UploadedFile::fake()->create('notes.docx', 40, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $this->api('POST', 'files', $user, ['file' => $docx, 'purpose' => 'document'])->assertCreated();

        $pptx = UploadedFile::fake()->create('slides.pptx', 40, 'application/vnd.openxmlformats-officedocument.presentationml.presentation');
        $this->api('POST', 'files', $user, ['file' => $pptx, 'purpose' => 'document'])->assertCreated();

        $exe = UploadedFile::fake()->create('tool.exe', 10, 'application/x-msdownload');
        $this->api('POST', 'files', $user, ['file' => $exe, 'purpose' => 'document'])->assertUnprocessable();
    }
}
