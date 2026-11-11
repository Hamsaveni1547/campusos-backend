<?php

namespace App\Modules\AI\Support;

/**
 * What a model answered: plain text, parsed JSON (structured output) or tool calls, plus token usage.
 */
final readonly class ModelReply
{
    /**
     * @param  array<string, mixed>|null  $json
     * @param  list<array{id: string, name: string, arguments: array<string, mixed>}>  $toolCalls
     */
    public function __construct(
        public string $provider,
        public string $model,
        public string $text,
        public ?array $json = null,
        public array $toolCalls = [],
        public int $inputTokens = 0,
        public int $outputTokens = 0,
    ) {}

    public function wantsTools(): bool
    {
        return $this->toolCalls !== [];
    }
}
