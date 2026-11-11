<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Models\AiRequest;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private const GROQ = 'https://api.groq.com/openai/v1/chat/completions';

    private const GEMINI = 'https://generativelanguage.googleapis.com/v1beta/models/*';

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.provider' => 'groq', 'ai.fallback' => 'gemini', 'ai.providers.groq.key' => 'test-groq', 'ai.providers.gemini.key' => 'test-gemini']);
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private static function groq(array $json): PromiseInterface
    {
        return Http::response(['choices' => [['message' => ['content' => json_encode($json)]]], 'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 60]]);
    }

    public function test_writer_drafts_rewrites_and_falls_back_to_gemini(): void
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $student = $this->member($institution, ['student'], ['email' => 'stu@example.edu']);

        Http::fake([
            self::GROQ => Http::sequence()
                ->push(['choices' => [['message' => ['content' => json_encode(['title' => 'Holiday on Friday', 'body' => 'The college is closed on Friday.'])]]], 'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 60]])
                ->push(['error' => ['message' => 'rate limit']], 429),
            self::GEMINI => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['text' => 'ಶುಕ್ರವಾರ ಕಾಲೇಜಿಗೆ ರಜೆ.'])]]]]], 'usageMetadata' => ['promptTokenCount' => 50, 'candidatesTokenCount' => 20]]),
        ]);

        $this->api('GET', 'ai/status', $admin)->assertOk()->assertJsonPath('data.configured', true)->assertJsonPath('data.writing', true);
        $this->api('POST', 'ai/announcements/draft', $admin, ['idea' => 'college closed friday for festival', 'tone' => 'friendly'])->assertOk()->assertJsonPath('data.title', 'Holiday on Friday');
        $this->api('POST', 'ai/announcements/rewrite', $admin, ['text' => 'The college is closed on Friday.', 'mode' => 'kannada'])->assertOk()->assertJsonPath('data.text', 'ಶುಕ್ರವಾರ ಕಾಲೇಜಿಗೆ ರಜೆ.');
        $this->api('POST', 'ai/announcements/draft', $student, ['idea' => 'x', 'tone' => 'friendly'])->assertForbidden();

        Http::assertSent(fn (Request $r) => $r->url() === self::GROQ && $r->hasHeader('Authorization', 'Bearer test-groq') && $r['response_format']['type'] === 'json_object');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), ':generateContent') && $r->hasHeader('x-goog-api-key', 'test-gemini'));
        $this->assertSame(['groq', 'gemini'], AiRequest::query()->where('status', 'ok')->orderBy('id')->pluck('provider')->all());
    }

    public function test_complaint_category_suggestion_limit_and_usage(): void
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);
        $staff = $this->member($institution, ['staff'], ['email' => 'staff@example.edu']);
        foreach (['Infrastructure', 'Academic'] as $name) {
            $this->api('POST', 'complaint-categories', $admin, ['name' => $name, 'sla_hours' => 48, 'is_restricted' => false])->assertCreated();
        }
        $this->setting($institution, 'ai', ['daily_limit_per_user' => 1]);
        Http::fake([self::GROQ => self::groq(['category' => 'Infrastructure', 'urgency' => 'medium', 'reason' => 'Building repair.'])]);

        $this->api('POST', 'ai/complaints/suggest-category', $staff, ['subject' => 'Leaking roof', 'description' => 'Water leaks in lab 2 when it rains.'])->assertOk()
            ->assertJsonPath('data.category', 'Infrastructure')->assertJsonPath('data.urgency', 'medium');
        $this->api('POST', 'ai/complaints/suggest-category', $staff, ['subject' => 'Again', 'description' => 'Another long description here.'])->assertStatus(429)->assertJsonPath('code', 'AI_LIMIT');

        $this->api('GET', 'ai/usage', $staff)->assertForbidden();
        $this->api('GET', 'ai/usage', $admin)->assertOk()->assertJsonPath('data.totals.calls', 2)->assertJsonPath('data.totals.limited', 1);
    }

    public function test_ai_off_without_keys_and_failure_is_friendly(): void
    {
        $institution = $this->institution();
        $admin = $this->member($institution, ['institution_admin']);

        config(['ai.provider' => 'none']);
        $this->api('GET', 'ai/status', $admin)->assertOk()->assertJsonPath('data.configured', false)->assertJsonPath('data.writing', false);
        $this->api('POST', 'ai/announcements/draft', $admin, ['idea' => 'x', 'tone' => 'formal'])->assertNotFound()->assertJsonPath('code', 'AI_DISABLED');

        config(['ai.provider' => 'groq', 'ai.providers.gemini.key' => null]);
        Http::fake([self::GROQ => Http::response(['error' => ['message' => 'down']], 500)]);
        $this->api('POST', 'ai/announcements/draft', $admin, ['idea' => 'x', 'tone' => 'formal'])->assertStatus(503)->assertJsonPath('code', 'AI_UNAVAILABLE');
    }
}
