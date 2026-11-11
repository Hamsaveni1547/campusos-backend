<?php

namespace App\Modules\AI\Clients;

use App\Modules\AI\Contracts\LanguageModel;
use App\Modules\AI\Support\AiUnavailable;
use App\Modules\AI\Support\ModelReply;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Google Gemini (generateContent API): structured JSON output, tool calling and reading documents.
 */
class GeminiClient implements LanguageModel
{
    /**
     * @param  array{key: string|null, url: string, models: array{fast: string, smart: string}}  $config
     */
    public function __construct(private array $config, private int $timeout) {}

    public function provider(): string
    {
        return 'gemini';
    }

    public function chat(array $messages, string $tier = 'fast', ?array $schema = null, array $tools = []): ModelReply
    {
        $model = $this->config['models'][$tier];
        $system = collect($messages)->where('role', 'system')->pluck('content')->implode("\n\n");
        $body = [
            'contents' => array_values(array_map($this->content(...), array_filter($messages, fn (array $m): bool => $m['role'] !== 'system'))),
            'generationConfig' => ['temperature' => 0.3],
        ];

        if ($system !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $system]]];
        }
        if ($schema !== null) {
            $body['generationConfig']['responseMimeType'] = 'application/json';
            $body['generationConfig']['responseSchema'] = $schema;
        }
        if ($tools !== []) {
            $body['tools'] = [['functionDeclarations' => $tools]];
        }

        return $this->reply($model, $this->post($model, $body), $schema !== null);
    }

    public function readDocument(string $instruction, string $mimeType, string $contents, array $schema): ModelReply
    {
        $model = $this->config['models']['smart'];
        $body = [
            'contents' => [['role' => 'user', 'parts' => [['inline_data' => ['mime_type' => $mimeType, 'data' => base64_encode($contents)]], ['text' => $instruction]]]],
            'generationConfig' => ['temperature' => 0, 'responseMimeType' => 'application/json', 'responseSchema' => $schema],
        ];

        return $this->reply($model, $this->post($model, $body), true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function reply(string $model, array $data, bool $json): ModelReply
    {
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        $text = collect($parts)->pluck('text')->filter()->implode('');
        $calls = collect($parts)->pluck('functionCall')->filter()->values()
            ->map(fn (array $c, int $i): array => ['id' => 'call_'.$i.'_'.$c['name'], 'name' => (string) $c['name'], 'arguments' => (array) ($c['args'] ?? [])])->all();

        return new ModelReply(
            provider: 'gemini',
            model: $model,
            text: $text,
            json: $json && $calls === [] ? Json::decode($text) : null,
            toolCalls: $calls,
            inputTokens: (int) ($data['usageMetadata']['promptTokenCount'] ?? 0),
            outputTokens: (int) ($data['usageMetadata']['candidatesTokenCount'] ?? 0),
        );
    }

    /**
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>
     */
    private function content(array $m): array
    {
        return match ($m['role']) {
            'tool' => ['role' => 'user', 'parts' => [['functionResponse' => ['name' => $m['name'], 'response' => ['result' => json_decode((string) $m['content'], true) ?? $m['content']]]]]],
            'assistant' => ['role' => 'model', 'parts' => ($m['tool_calls'] ?? []) === []
                ? [['text' => $m['content']]]
                : array_map(fn (array $c): array => ['functionCall' => ['name' => $c['name'], 'args' => (object) $c['arguments']]], $m['tool_calls'])],
            default => ['role' => 'user', 'parts' => [['text' => $m['content']]]],
        };
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(string $model, array $body): array
    {
        if (blank($this->config['key'])) {
            throw new AiUnavailable('Gemini is not configured.');
        }

        try {
            $response = Http::acceptJson()->timeout($this->timeout)->withHeaders(['x-goog-api-key' => (string) $this->config['key']])
                ->post(rtrim($this->config['url'], '/').'/'.$model.':generateContent', $body);
        } catch (ConnectionException $e) {
            throw new AiUnavailable('Gemini could not be reached.', 0, $e);
        }

        if (! $response->successful()) {
            throw new AiUnavailable('Gemini answered '.$response->status().': '.mb_substr((string) $response->json('error.message', $response->body()), 0, 200));
        }

        return (array) $response->json();
    }
}
