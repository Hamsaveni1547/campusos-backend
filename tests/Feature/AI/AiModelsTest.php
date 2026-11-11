<?php

namespace Tests\Feature\AI;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_model_list_choice_in_settings_and_connection_test(): void
    {
        config(['ai.provider' => 'groq', 'ai.fallback' => 'gemini', 'ai.providers.groq.key' => 'k1', 'ai.providers.gemini.key' => null]);
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);

        Http::fake([
            'https://api.groq.com/openai/v1/models' => Http::response(['data' => [['id' => 'openai/gpt-oss-120b'], ['id' => 'whisper-large-v3'], ['id' => 'qwen/qwen3.8-27b']]]),
            'https://api.groq.com/openai/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'OK']]]]),
        ]);

        $groq = $this->api('GET', 'ai/models', $admin)->assertOk()->json('data.providers.0');
        $this->assertSame(['openai/gpt-oss-120b', 'qwen/qwen3.8-27b'], $groq['models'], 'speech models are left out');
        $this->assertFalse($this->api('GET', 'ai/models', $admin)->json('data.providers.1.configured'));

        $this->api('PUT', 'settings/ai', $admin, ['groq_fast_model' => 'qwen/qwen3.8-27b'])->assertOk();
        $this->api('POST', 'ai/models/test', $admin, ['provider' => 'groq'])->assertOk()->assertJsonPath('data.ok', true)->assertJsonPath('data.reply', 'OK');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'chat/completions') && $r['model'] === 'qwen/qwen3.8-27b');

        $this->api('POST', 'ai/models/test', $admin, ['provider' => 'gemini'])->assertOk()->assertJsonPath('data.ok', false);
    }
}
