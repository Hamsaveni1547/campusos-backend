<?php

namespace App\Modules\AI\Assistant\Tools;

use App\Models\User;
use App\Modules\AI\Contracts\AssistantTool;
use App\Modules\Communication\Models\AnnouncementRecipient;
use stdClass;

class LatestAnnouncements implements AssistantTool
{
    public function name(): string
    {
        return 'latest_announcements';
    }

    public function description(): string
    {
        return 'The 5 most recent announcements sent to this person, with a short extract, and whether unread or waiting for acknowledgement.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new stdClass];
    }

    public function availableTo(User $user): bool
    {
        return true;
    }

    public function run(User $user, array $arguments): array
    {
        return ['announcements' => AnnouncementRecipient::query()->with('announcement')->where('user_id', $user->id)
            ->whereHas('announcement', fn ($q) => $q->where('status', 'published'))
            ->join('announcements', 'announcements.id', '=', 'announcement_recipients.announcement_id')->orderByDesc('announcements.published_at')
            ->select('announcement_recipients.*')->limit(5)->get()
            ->map(fn (AnnouncementRecipient $r): array => [
                'title' => $r->announcement->title,
                'extract' => str($r->announcement->body)->squish()->limit(200)->toString(),
                'published' => $r->announcement->published_at?->toDateString(),
                'unread' => $r->read_at === null,
                'needs_acknowledgement' => $r->announcement->requires_ack && $r->acknowledged_at === null,
            ])->values()->all()];
    }
}
