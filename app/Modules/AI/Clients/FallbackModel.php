<?php

namespace App\Modules\AI\Clients;

use App\Modules\AI\Contracts\LanguageModel;
use App\Modules\AI\Support\AiUnavailable;
use App\Modules\AI\Support\ModelReply;

/**
 * Tries the main provider, then the fallback when the main one is down, rate-limited or answers badly.
 * Reading documents goes to the first provider that can do it.
 */
class FallbackModel implements LanguageModel
{
    /**
     * @param  list<LanguageModel>  $models  in order of preference
     */
    public function __construct(private array $models) {}

    public function provider(): string
    {
        return implode('+', array_map(fn (LanguageModel $m): string => $m->provider(), $this->models));
    }

    public function chat(array $messages, string $tier = 'fast', ?array $schema = null, array $tools = []): ModelReply
    {
        return $this->first(fn (LanguageModel $m): ModelReply => $m->chat($messages, $tier, $schema, $tools));
    }

    public function readDocument(string $instruction, string $mimeType, string $contents, array $schema): ModelReply
    {
        return $this->first(fn (LanguageModel $m): ModelReply => $m->readDocument($instruction, $mimeType, $contents, $schema));
    }

    /**
     * @param  callable(LanguageModel): ModelReply  $call
     */
    private function first(callable $call): ModelReply
    {
        $errors = [];

        foreach ($this->models as $model) {
            try {
                return $call($model);
            } catch (AiUnavailable $e) {
                // Keep each provider's reason (with the network cause) so the log shows why every one failed.
                $errors[] = $model->provider().': '.$e->getMessage().($e->getPrevious() ? ' ('.$e->getPrevious()->getMessage().')' : '');
            }
        }

        throw new AiUnavailable($errors === [] ? 'No AI provider is configured.' : implode(' | ', $errors));
    }
}
