<?php

namespace App\Modules\Communication\Http\Controllers;

use App\Models\User;
use App\Modules\Communication\Enums\NotificationCategory;
use App\Modules\Communication\Enums\NotificationChannel;
use App\Modules\Communication\Support\NotificationPreferences;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Routing\Controller;

/**
 * @tags My notifications
 */
class MyNotificationController extends Controller
{
    public function __construct(private CurrentInstitution $currentInstitution) {}

    /**
     * In-app notifications (newest first). Filters: `filter[unread]=1`, `filter[category]`.
     */
    public function index(Request $request): JsonResponse
    {
        $notifications = $this->query($request)
            ->when($request->boolean('filter.unread'), fn ($query) => $query->whereNull('read_at'))
            ->when($request->filled('filter.category'), fn ($query) => $query->where('category', $request->input('filter.category')))
            ->latest()
            ->cursorPaginate(min((int) $request->integer('per_page', 20), 100));

        return response()->json([
            'data' => collect($notifications->items())->map(fn (DatabaseNotification $notification): array => [
                'id' => $notification->id,
                'category' => $notification->getAttribute('category'),
                'title' => $notification->data['title'] ?? null,
                'body' => $notification->data['body'] ?? null,
                'action_url' => $notification->data['action_url'] ?? null,
                'action_text' => $notification->data['action_text'] ?? null,
                'read_at' => $notification->read_at?->toIso8601String(),
                'created_at' => $notification->created_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'next_cursor' => $notifications->nextCursor()?->encode(),
                'per_page' => $notifications->perPage(),
            ],
        ]);
    }

    /**
     * Number of unread notifications (for the bell badge).
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['data' => ['unread' => $this->query($request)->whereNull('read_at')->count()]]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $this->query($request)->whereKey($notification)->firstOrFail()->markAsRead();

        return response()->json(['message' => 'Marked as read.']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $count = $this->query($request)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['message' => "{$count} notification(s) marked as read."]);
    }

    public function destroy(Request $request, string $notification): JsonResponse
    {
        $this->query($request)->whereKey($notification)->firstOrFail()->delete();

        return response()->json(['message' => 'Notification deleted.']);
    }

    /**
     * Channel choices per category. Security and account messages are always delivered.
     */
    public function preferences(Request $request, NotificationPreferences $preferences): JsonResponse
    {
        return response()->json(['data' => $preferences->matrix($this->user($request))]);
    }

    public function updatePreferences(Request $request, NotificationPreferences $preferences): JsonResponse
    {
        $validated = $request->validate([
            /** Map of category => { channel: enabled }. */
            'preferences' => ['required', 'array'],
            'preferences.*' => ['array'],
            'preferences.*.*' => ['boolean'],
        ]);

        $categories = array_column(NotificationCategory::cases(), 'value');
        $channels = array_column(NotificationChannel::cases(), 'value');

        $request->validate([
            'preferences' => [function (string $attribute, mixed $value, \Closure $fail) use ($categories, $channels): void {
                foreach ((array) $value as $category => $map) {
                    if (! in_array($category, $categories, true)) {
                        $fail("Unknown category [{$category}].");
                    }

                    foreach (array_keys((array) $map) as $channel) {
                        if (! in_array($channel, $channels, true)) {
                            $fail("Unknown channel [{$channel}].");
                        }
                    }
                }
            }],
        ]);

        $preferences->update($this->user($request), $validated['preferences']);

        return response()->json(['data' => $preferences->matrix($this->user($request))]);
    }

    /**
     * @return Builder<DatabaseNotification>
     */
    private function query(Request $request)
    {
        $institutionId = $this->currentInstitution->id();

        return DatabaseNotification::query()
            ->where('notifiable_type', $this->user($request)->getMorphClass())
            ->where('notifiable_id', $this->user($request)->id)
            ->where(fn ($query) => $query->whereNull('institution_id')->when($institutionId !== null, fn ($query) => $query->orWhere('institution_id', $institutionId)));
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
