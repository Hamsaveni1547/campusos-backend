<?php

namespace Tests\Feature\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAndReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_cards_follow_permissions(): void
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $librarian = $this->member($institution, ['librarian'], ['email' => 'lib@example.edu']);

        $keys = array_column($this->api('GET', 'me/dashboard', $admin)->assertOk()->json('data.cards'), 'key');
        $this->assertContains('students', $keys);
        $this->assertContains('fees-overdue', $keys);
        $this->assertContains('complaints', $keys);

        $keys = array_column($this->api('GET', 'me/dashboard', $librarian)->assertOk()->json('data.cards'), 'key');
        $this->assertSame(['announcements', 'library-overdue'], $keys);
    }

    public function test_catalogue_and_runs_respect_permissions(): void
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $librarian = $this->member($institution, ['librarian'], ['email' => 'lib@example.edu']);

        $all = array_column($this->api('GET', 'reports', $admin)->assertOk()->json('data'), 'key');
        $this->assertCount(8, $all);

        foreach ($all as $key) {
            $this->api('GET', "reports/{$key}?filter[from]=2026-01-01&filter[to]=2026-12-31", $admin)->assertOk()->assertJsonPath('data.key', $key)->assertJsonPath('data.rows', []);
        }

        $this->assertSame(['library-overdue'], array_column($this->api('GET', 'reports', $librarian)->json('data'), 'key'));
        $this->api('GET', 'reports/fee-defaulters', $librarian)->assertForbidden();
        $this->api('GET', 'reports/no-such-report', $admin)->assertNotFound();
        $this->api('GET', 'reports/fee-collection?filter[from]=2026-02-01&filter[to]=2026-01-01', $admin)->assertUnprocessable();
    }
}
