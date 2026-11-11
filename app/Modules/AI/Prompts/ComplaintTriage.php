<?php

namespace App\Modules\AI\Prompts;

use App\Modules\AI\Contracts\LanguageModel;
use App\Modules\AI\Support\ModelReply;

/**
 * Sorts a complaint into a category with urgency, and summarises a complaint thread for the handler.
 * The complainant's name is never sent.
 */
class ComplaintTriage
{
    /**
     * @param  list<string>  $categories
     */
    public function classify(LanguageModel $model, string $subject, string $description, array $categories): ModelReply
    {
        return $model->chat([
            ['role' => 'system', 'content' => 'You sort grievances at an Indian college. Choose exactly one category from the list. Urgency is high for safety, harassment, health or exam-day problems; medium when studies or money are affected; otherwise low.'],
            ['role' => 'user', 'content' => 'Categories: '.implode(' | ', $categories)."\n\nSubject: {$subject}\nDetails: {$description}"],
        ], 'fast', [
            'type' => 'object',
            'properties' => [
                'category' => ['type' => 'string', 'enum' => $categories],
                'urgency' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                'reason' => ['type' => 'string', 'description' => 'One short sentence'],
            ],
            'required' => ['category', 'urgency', 'reason'],
        ]);
    }

    /**
     * @param  list<array{from: string, body: string}>  $messages
     */
    public function summarise(LanguageModel $model, string $category, string $subject, string $description, array $messages): ModelReply
    {
        $thread = collect($messages)->map(fn (array $m): string => '- '.$m['from'].': '.$m['body'])->implode("\n");

        return $model->chat([
            ['role' => 'system', 'content' => 'You help a college grievance officer. Be factual and brief. Do not take sides.'],
            ['role' => 'user', 'content' => "Category: {$category}\nSubject: {$subject}\nComplaint: {$description}\nConversation so far:\n".($thread ?: '(none)')],
        ], 'smart', [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string', 'description' => 'At most 3 sentences'],
                'urgency' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                'next_step' => ['type' => 'string', 'description' => 'One concrete next action for the officer'],
            ],
            'required' => ['summary', 'urgency', 'next_step'],
        ]);
    }
}
