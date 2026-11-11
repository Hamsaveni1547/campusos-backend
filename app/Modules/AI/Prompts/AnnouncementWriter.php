<?php

namespace App\Modules\AI\Prompts;

use App\Modules\AI\Contracts\LanguageModel;
use App\Modules\AI\Support\ModelReply;

/**
 * Writes and rewrites announcements (circulars) for a college, and translates them to Kannada.
 */
class AnnouncementWriter
{
    public const MODES = ['improve', 'shorten', 'formal', 'kannada', 'english'];

    private const STYLE = 'You write announcements for an Indian engineering college (CampusOS). Clear, polite, specific. Use short paragraphs. Never invent dates, names, amounts or places that were not given; leave a placeholder like [date] instead.';

    /**
     * One line → a full announcement.
     */
    public function draft(LanguageModel $model, string $idea, string $audience, string $tone): ModelReply
    {
        return $model->chat([
            ['role' => 'system', 'content' => self::STYLE],
            ['role' => 'user', 'content' => "Write an announcement.\nAudience: {$audience}\nTone: {$tone}\nWhat it is about: {$idea}"],
        ], 'smart', [
            'type' => 'object',
            'properties' => ['title' => ['type' => 'string', 'description' => 'At most 12 words'], 'body' => ['type' => 'string', 'description' => '2 to 4 short paragraphs']],
            'required' => ['title', 'body'],
        ]);
    }

    /**
     * Improve, shorten, make formal, or translate (Kannada ↔ English).
     */
    public function rewrite(LanguageModel $model, string $text, string $mode): ModelReply
    {
        $instruction = match ($mode) {
            'improve' => 'Improve the clarity and grammar. Keep the meaning and all facts.',
            'shorten' => 'Make it about half as long. Keep every fact (dates, places, amounts).',
            'formal' => 'Rewrite in a formal tone suitable for an official circular.',
            'kannada' => 'Translate into Kannada (ಕನ್ನಡ script). Keep names, codes, dates and numbers as they are.',
            'english' => 'Translate into clear English. Keep names, codes, dates and numbers as they are.',
            default => throw new \InvalidArgumentException('Unknown rewrite mode '.$mode),
        };

        return $model->chat([
            ['role' => 'system', 'content' => self::STYLE],
            ['role' => 'user', 'content' => $instruction."\n\nText:\n".$text],
        ], 'smart', ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']]);
    }

    /**
     * Two or three lines for a long circular, in English or Kannada.
     */
    public function summarise(LanguageModel $model, string $title, string $body, string $language): ModelReply
    {
        return $model->chat([
            ['role' => 'system', 'content' => self::STYLE.' Summarise for a busy student or teacher.'],
            ['role' => 'user', 'content' => 'Summarise in '.($language === 'kn' ? 'Kannada (ಕನ್ನಡ script)' : 'English')." in at most 3 short bullet points: what, when, what to do.\n\nTitle: {$title}\n\n{$body}"],
        ], 'fast', ['type' => 'object', 'properties' => ['points' => ['type' => 'array', 'items' => ['type' => 'string']]], 'required' => ['points']]);
    }
}
