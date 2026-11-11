<?php

namespace Tests\Feature\AI;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssistantTest extends TestCase
{
    use RefreshDatabase;

    private const GROQ = 'https://api.groq.com/openai/v1/chat/completions';

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.provider' => 'groq', 'ai.fallback' => 'none', 'ai.providers.groq.key' => 'test-groq']);
    }

    public function test_assistant_looks_things_up_with_tools_and_keeps_conversations_private(): void
    {
        $institution = $this->institution();
        $staff = $this->member($institution, ['staff']);
        $other = $this->member($institution, ['staff'], ['email' => 'other@example.edu']);

        Http::fake([self::GROQ => Http::sequence()
            ->push(['choices' => [['message' => ['content' => null, 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'my_library_loans', 'arguments' => '{}']]]]]], 'usage' => ['prompt_tokens' => 300, 'completion_tokens' => 20]])
            ->push(['choices' => [['message' => ['content' => 'You have no library books right now.']]], 'usage' => ['prompt_tokens' => 350, 'completion_tokens' => 15]]),
        ]);

        $tools = $this->api('GET', 'ai/assistant', $staff)->assertOk()->json('data.tools');
        $this->assertContains('my_library_loans', $tools);
        $this->assertNotContains('my_fees', $tools, 'student-only tools are not offered to staff');

        $answer = $this->api('POST', 'ai/assistant/messages', $staff, ['message' => 'Do I have any library books due?'])->assertOk()
            ->assertJsonPath('data.message.content', 'You have no library books right now.')
            ->assertJsonPath('data.message.tools', ['my_library_loans']);
        $conversation = $answer->json('data.conversation_id');

        Http::assertSent(fn (Request $r) => collect($r['messages'])->contains(fn ($m) => $m['role'] === 'tool' && $m['tool_call_id'] === 'call_1' && str_contains($m['content'], '"books":[]')));
        Http::assertSent(fn (Request $r) => str_contains($r['messages'][0]['content'], 'Kannada') && collect($r['tools'] ?? [])->pluck('function.name')->contains('my_dashboard'));

        $this->api('GET', "ai/assistant/conversations/{$conversation}", $staff)->assertOk()->assertJsonCount(2, 'data.messages');
        $this->api('GET', "ai/assistant/conversations/{$conversation}", $other)->assertNotFound();
        $this->api('GET', 'ai/assistant', $staff)->assertJsonPath('data.conversations.0.title', 'Do I have any library books due?');
        $this->api('DELETE', "ai/assistant/conversations/{$conversation}", $staff)->assertOk();

        $applicant = $this->member($institution, ['applicant'], ['email' => 'app@example.edu']);
        $this->api('POST', 'ai/assistant/messages', $applicant, ['message' => 'hello'])->assertForbidden();
    }
}
