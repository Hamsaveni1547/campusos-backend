<?php

namespace App\Modules\AI\Clients;

use App\Modules\AI\Contracts\LanguageModel;
use App\Modules\AI\Support\AiUnavailable;
use App\Modules\AI\Support\ModelReply;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Groq (OpenAI-compatible chat completions API): fast open models, tool calling and JSON mode.
 */
class GroqClient implements LanguageModel
{
    /**
     * @param  array{key: string|null, url: string, models: array{fast: string, smart: string}}  $config
     */
    public function __construct(private array $config, private int $timeout) {}

    public function provider(): string
    {
        return 'groq';
    }

    public function chat(array $messages, string $tier = 'fast', ?array $schema = null, array $tools = []): ModelReply
    {
        $model = $this->config['models'][$tier];
        $body = ['model' => $model, 'messages' => array_map($this->message(...), $messages), 'temperature' => 0.3];

        if ($schema !== null) {
            $body['response_format'] = ['type' => 'json_object'];
            $body['messages'][0]['content'] .= "\n\nAnswer with one JSON object that follows this JSON schema exactly:\n".json_encode($schema);
        }

        if ($tools !== []) {
            $body['tools'] = array_map(fn (array $t): array => ['type' => 'function', 'function' => $t], $tools);
            $body['tool_choice'] = 'auto';
        }

        $data = $this->post($body);
        $choice = $data['choices'][0]['message'] ?? [];
        $text = (string) ($choice['content'] ?? '');

        return new ModelReply(
            provider: 'groq',
            model: $model,
            text: $text,
            json: $schema !== null ? Json::decode($text) : null,
            toolCalls: array_map(fn (array $c): array => ['id' => (string) $c['id'], 'name' => (string) $c['function']['name'], 'arguments' => (array) (json_decode((string) $c['function']['arguments'], true) ?? [])], $choice['tool_calls'] ?? []),
            inputTokens: (int) ($data['usage']['prompt_tokens'] ?? 0),
            outputTokens: (int) ($data['usage']['completion_tokens'] ?? 0),
        );
    }

    public function readDocument(string $instruction, string $mimeType, string $contents, array $schema): ModelReply
    {
        throw new AiUnavailable('Reading documents uses Gemini.');
    }

    /**
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>
     */
    private function message(array $m): array
    {
        return match ($m['role']) {
            'tool' => ['role' => 'tool', 'tool_call_id' => $m['tool_call_id'], 'content' => $m['content']],
            'assistant' => ($m['tool_calls'] ?? []) === []
                ? ['role' => 'assistant', 'content' => $m['content']]
                : ['role' => 'assistant', 'content' => null, 'tool_calls' => array_map(fn (array $c): array => ['id' => $c['id'], 'type' => 'function', 'function' => ['name' => $c['name'], 'arguments' => json_encode((object) $c['arguments'])]], $m['tool_calls'])],
            default => ['role' => $m['role'], 'content' => $m['content']],
        };
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(array $body): array
    {
        if (blank($this->config['key'])) {
            throw new AiUnavailable('Groq is not configured.');
        }

        try {
            $response = Http::withToken((string) $this->config['key'])->acceptJson()->timeout($this->timeout)->post($this->config['url'], $body);
        } catch (ConnectionException $e) {
            throw new AiUnavailable('Groq could not be reached.', 0, $e);
        }

        if (! $response->successful()) {
            throw new AiUnavailable('Groq answered '.$response->status().': '.mb_substr((string) $response->json('error.message', $response->body()), 0, 200));
        }

        return (array) $response->json();
    }
}
