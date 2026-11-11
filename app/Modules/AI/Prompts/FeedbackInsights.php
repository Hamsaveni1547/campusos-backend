<?php

namespace App\Modules\AI\Prompts;

use App\Modules\AI\Contracts\LanguageModel;
use App\Modules\AI\Support\ModelReply;

/**
 * Themes, sentiment and a short summary from anonymous feedback comments.
 */
class FeedbackInsights
{
    /** Enough comments for a useful picture without large prompts. */
    public const MAX_COMMENTS = 150;

    /**
     * @param  list<string>  $comments
     */
    public function analyse(LanguageModel $model, string $surveyTitle, array $comments): ModelReply
    {
        $list = collect($comments)->take(self::MAX_COMMENTS)->map(fn (string $c, int $i): string => ($i + 1).'. '.str_replace("\n", ' ', mb_substr($c, 0, 400)))->implode("\n");

        return $model->chat([
            ['role' => 'system', 'content' => 'You analyse anonymous student feedback for teachers at an Indian college. Be fair and constructive; quote no single student. Comments may be in English or Kannada.'],
            ['role' => 'user', 'content' => "Survey: {$surveyTitle}\nComments:\n{$list}"],
        ], 'smart', [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string', 'description' => 'Two sentences'],
                'sentiment' => [
                    'type' => 'object',
                    'properties' => ['positive' => ['type' => 'integer'], 'neutral' => ['type' => 'integer'], 'negative' => ['type' => 'integer']],
                    'required' => ['positive', 'neutral', 'negative'],
                    'description' => 'How many comments are positive, neutral, negative',
                ],
                'themes' => [
                    'type' => 'array',
                    'description' => 'Three to five themes, most mentioned first',
                    'items' => [
                        'type' => 'object',
                        'properties' => ['theme' => ['type' => 'string'], 'mentions' => ['type' => 'integer'], 'tone' => ['type' => 'string', 'enum' => ['positive', 'mixed', 'negative']]],
                        'required' => ['theme', 'mentions', 'tone'],
                    ],
                ],
                'suggestion' => ['type' => 'string', 'description' => 'One practical suggestion for the teacher'],
            ],
            'required' => ['summary', 'sentiment', 'themes', 'suggestion'],
        ]);
    }
}
