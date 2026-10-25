<?php

namespace Tests\Feature\Shared;

use App\Shared\Audit\SecurityEvent;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrustedFrontendTest extends TestCase
{
    use RefreshDatabase;

    public function test_trusted_frontend_forwards_browser_ip_and_user_agent(): void
    {
        $user = $this->member($this->institution());

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['X-Client-IP' => '203.0.113.7', 'X-Client-User-Agent' => 'Firefox on Linux'])
            ->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => UserFactory::PASSWORD, 'device_name' => 'Web'])
            ->assertOk();

        $event = SecurityEvent::query()->latest('id')->firstOrFail();
        $this->assertSame('203.0.113.7', $event->ip_address);
        $this->assertSame('Firefox on Linux', $event->user_agent);
    }

    public function test_forwarded_headers_are_ignored_from_other_callers(): void
    {
        $user = $this->member($this->institution());

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->withHeaders(['X-Client-IP' => '203.0.113.7', 'X-Client-User-Agent' => 'Spoofed'])
            ->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => UserFactory::PASSWORD, 'device_name' => 'Web'])
            ->assertOk();

        $event = SecurityEvent::query()->latest('id')->firstOrFail();
        $this->assertSame('198.51.100.20', $event->ip_address);
        $this->assertNotSame('Spoofed', $event->user_agent);
    }
}
