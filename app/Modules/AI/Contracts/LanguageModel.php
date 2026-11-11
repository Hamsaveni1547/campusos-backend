<?php

namespace App\Modules\AI\Contracts;

use App\Modules\AI\Support\ModelReply;

/**
 * A chat model behind any provider (Groq, Gemini, …). Features talk to this contract only.
 *
 * Messages use one shape for every provider:
 *   ['role' => 'system'|'user'|'assistant', 'content' => string]
 *   ['role' => 'assistant', 'content' => '', 'tool_calls' => list<array{id: string, name: string, arguments: array<string, mixed>}>]
 *   ['role' => 'tool', 'tool_call_id' => string, 'name' => string, 'content' => string]
 *
 * Tools: list<array{name: string, description: string, parameters: array<string, mixed>}> (JSON schema).
 */
interface LanguageModel
{
    public function provider(): string;

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  array<string, mixed>|null  $schema  JSON schema the answer must follow (structured output)
     * @param  list<array{name: string, description: string, parameters: array<string, mixed>}>  $tools
     * @param  'fast'|'smart'  $tier
     */
    public function chat(array $messages, string $tier = 'fast', ?array $schema = null, array $tools = []): ModelReply;

    /**
     * Read a document image or PDF and answer as JSON following the schema (OCR / document understanding).
     *
     * @param  array<string, mixed>  $schema
     */
    public function readDocument(string $instruction, string $mimeType, string $contents, array $schema): ModelReply;
}
