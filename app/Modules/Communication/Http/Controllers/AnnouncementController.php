<?php

namespace App\Modules\Communication\Http\Controllers;

use App\Models\User;
use App\Modules\Communication\Authorization\CommunicationPermissions as P;
use App\Modules\Communication\Enums\NotificationCategory;
use App\Modules\Communication\Models\Announcement;
use App\Modules\Communication\Models\AnnouncementRecipient;
use App\Modules\Communication\Services\AnnouncementService;
use App\Modules\Identity\Authorization\SystemRole;
use App\Modules\Institution\Models\Batch;
use App\Modules\Institution\Models\Department;
use App\Modules\Institution\Models\Programme;
use App\Modules\Institution\Models\Section;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Http\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * @tags Announcements
 */
class AnnouncementController extends Controller
{
    /** @var array<string, class-string<Model>> */
    private const AUDIENCE_MODELS = ['departments' => Department::class, 'programmes' => Programme::class, 'batches' => Batch::class, 'sections' => Section::class];

    public function __construct(private AnnouncementService $announcements) {}

    // ----- reader ----------------------------------------------------------

    /**
     * Announcements for me: pinned first, then newest. `filter[unread]=1` for unread only.
     */
    public function feed(Request $request): JsonResponse
    {
        $page = AnnouncementRecipient::query()->with('announcement.author')->where('user_id', $this->user($request)->id)
            ->whereHas('announcement', fn ($q) => $q->where('status', 'published')->where(fn ($q) => $q->whereNull('expires_on')->orWhere('expires_on', '>=', today())))
            ->when($request->boolean('filter.unread'), fn ($q) => $q->whereNull('read_at'))
            ->join('announcements', 'announcements.id', '=', 'announcement_recipients.announcement_id')
            ->orderByDesc('announcements.is_pinned')->orderByDesc('announcements.published_at')
            ->select('announcement_recipients.*')
            ->paginate(min(50, max(5, $request->integer('per_page', 20))));

        return ApiResponse::paginated($page, fn (AnnouncementRecipient $r): array => [...$this->present($r->announcement, false), 'read_at' => $r->read_at?->toIso8601String(), 'acknowledged_at' => $r->acknowledged_at?->toIso8601String()]);
    }

    /**
     * One announcement. Opening it marks it read for a recipient.
     */
    public function show(Request $request, Announcement $announcement): JsonResponse
    {
        $user = $this->user($request);
        $recipient = AnnouncementRecipient::query()->where('announcement_id', $announcement->id)->where('user_id', $user->id)->first();
        $isPublisher = $this->canManage($user, $announcement);

        abort_unless($isPublisher || ($recipient && $announcement->status === 'published'), 404);

        if ($recipient && $recipient->read_at === null) {
            $recipient->forceFill(['read_at' => now()])->save();
        }

        return ApiResponse::data([...$this->present($announcement, $isPublisher), 'read_at' => $recipient?->read_at?->toIso8601String(), 'acknowledged_at' => $recipient?->acknowledged_at?->toIso8601String(), 'can_manage' => $isPublisher]);
    }

    public function acknowledge(Request $request, Announcement $announcement): JsonResponse
    {
        $recipient = AnnouncementRecipient::query()->where('announcement_id', $announcement->id)->where('user_id', $this->user($request)->id)->firstOrFail();

        if (! $announcement->requires_ack) {
            throw new BusinessRuleException('This announcement does not ask for acknowledgement.', 'NO_ACK_NEEDED', 422);
        }

        $recipient->forceFill(['read_at' => $recipient->read_at ?? now(), 'acknowledged_at' => $recipient->acknowledged_at ?? now()])->save();

        return ApiResponse::message('Acknowledged.');
    }

    // ----- publisher -------------------------------------------------------

    /**
     * Announcements I can manage (all of them with "publish to everyone"). `filter[status]`.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($user->canAny([P::AnnouncementsPublish, P::AnnouncementsPublishAll]), 403);

        $page = Announcement::query()->with('author')
            ->unless($user->can(P::AnnouncementsPublishAll), fn ($q) => $q->where('created_by', $user->id))
            ->when($request->input('filter.status'), fn ($q, $status) => $q->where('status', $status))
            ->withCount(['recipients as read_count' => fn ($q) => $q->whereNotNull('read_at'), 'recipients as ack_count' => fn ($q) => $q->whereNotNull('acknowledged_at')])
            ->latest('id')->paginate(20);

        return ApiResponse::paginated($page, fn (Announcement $a): array => [...$this->present($a, true), 'read_count' => (int) $a->getAttribute('read_count'), 'ack_count' => (int) $a->getAttribute('ack_count')]);
    }

    /**
     * Options for the audience picker the current user may use.
     */
    public function audiences(Request $request): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($user->canAny([P::AnnouncementsPublish, P::AnnouncementsPublishAll]), 403);

        $limits = $this->announcements->limitsFor($user);

        return ApiResponse::data([
            'types' => $limits['types'],
            'limits' => [
                'departments' => $limits['departments'] === null ? null : Department::query()->whereIn('id', $limits['departments'])->pluck('ulid')->all(),
                'sections' => $limits['sections'] === null ? null : Section::query()->whereIn('id', $limits['sections'])->pluck('ulid')->all(),
            ],
            'roles' => collect(SystemRole::cases())->reject(fn (SystemRole $r) => $r === SystemRole::Applicant)->map(fn (SystemRole $r): array => ['value' => $r->value, 'label' => $r->displayName()])->values(),
            'categories' => collect(NotificationCategory::cases())->reject(fn (NotificationCategory $c) => in_array($c, [NotificationCategory::Security, NotificationCategory::Account, NotificationCategory::System], true))
                ->map(fn (NotificationCategory $c): array => ['value' => $c->value, 'label' => $c->label()])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $announcement = new Announcement($this->validated($request, $user));
        $announcement->save();

        return ApiResponse::data($this->present($announcement->refresh(), true), 201);
    }

    public function update(Request $request, Announcement $announcement): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($this->canManage($user, $announcement), 403);

        if ($announcement->status !== 'draft') {
            throw new BusinessRuleException('A published announcement cannot be edited. Withdraw it and post a new one.', 'NOT_DRAFT', 422);
        }

        $announcement->update($this->validated($request, $user));

        return ApiResponse::data($this->present($announcement, true));
    }

    public function destroy(Request $request, Announcement $announcement): JsonResponse
    {
        abort_unless($this->canManage($this->user($request), $announcement), 403);

        if ($announcement->status !== 'draft') {
            throw new BusinessRuleException('Only drafts can be deleted.', 'NOT_DRAFT', 422);
        }

        $announcement->delete();

        return ApiResponse::message('Draft deleted.');
    }

    public function publish(Request $request, Announcement $announcement): JsonResponse
    {
        abort_unless($this->canManage($this->user($request), $announcement), 403);
        $message = $this->announcements->publishOrSchedule($announcement);

        return ApiResponse::data(['message' => $message, 'recipients' => $announcement->recipients_count, 'status' => $announcement->status]);
    }

    public function withdraw(Request $request, Announcement $announcement): JsonResponse
    {
        abort_unless($this->canManage($this->user($request), $announcement), 403);
        $this->announcements->withdraw($announcement);

        return ApiResponse::message('Announcement withdrawn.');
    }

    /**
     * Read and acknowledgement counts, and who has not acknowledged yet (first 200).
     */
    public function readStats(Request $request, Announcement $announcement): JsonResponse
    {
        abort_unless($this->canManage($this->user($request), $announcement), 403);
        $base = fn () => AnnouncementRecipient::query()->where('announcement_id', $announcement->id);

        return ApiResponse::data([
            'recipients' => $base()->count(),
            'read' => $base()->whereNotNull('read_at')->count(),
            'acknowledged' => $base()->whereNotNull('acknowledged_at')->count(),
            'pending' => $base()->with('user')->whereNull($announcement->requires_ack ? 'acknowledged_at' : 'read_at')->limit(200)->get()
                ->map(fn (AnnouncementRecipient $r): array => ['name' => $r->user->name, 'email' => $r->user->email])->values(),
        ]);
    }

    // ----- helpers ---------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, User $user): array
    {
        abort_unless($user->canAny([P::AnnouncementsPublish, P::AnnouncementsPublishAll]), 403);
        $types = $this->announcements->limitsFor($user)['types'];

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
            'category' => ['required', Rule::in(array_column(NotificationCategory::cases(), 'value'))],
            'priority' => ['required', Rule::in(['normal', 'important'])],
            'audience_type' => ['required', Rule::in($types)],
            'audience_ids' => [Rule::requiredIf(! in_array($request->input('audience_type'), ['everyone', 'staff', 'students'], true)), 'array'],
            'audience_ids.*' => ['string', 'max:40'],
            'is_pinned' => ['boolean'],
            'requires_ack' => ['boolean'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:today'],
            'publish_at' => ['nullable', 'date', 'after:now'],
        ]);

        $type = $data['audience_type'];
        $ids = $data['audience_ids'] ?? [];

        $data['audience_ids'] = match (true) {
            in_array($type, ['everyone', 'staff', 'students'], true) => null,
            $type === 'roles' => array_values(array_intersect($ids, array_column(SystemRole::cases(), 'value'))),
            default => self::AUDIENCE_MODELS[$type]::query()->whereIn('ulid', $ids)->pluck('id')->all(),
        };

        if ($data['audience_ids'] === []) {
            throw new BusinessRuleException('Choose who should receive this announcement.', 'EMPTY_AUDIENCE', 422);
        }

        $this->announcements->assertWithinLimits($user, $type, $data['audience_ids'] ?? []);

        return $data;
    }

    private function canManage(User $user, Announcement $announcement): bool
    {
        return $user->can(P::AnnouncementsPublishAll) || ($user->can(P::AnnouncementsPublish) && $announcement->created_by === $user->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Announcement $a, bool $forPublisher): array
    {
        return [
            'id' => $a->ulid,
            'title' => $a->title,
            'body' => $a->body,
            'category' => $a->category,
            'priority' => $a->priority,
            'is_pinned' => $a->is_pinned,
            'requires_ack' => $a->requires_ack,
            'expires_on' => $a->expires_on?->toDateString(),
            'published_at' => $a->published_at?->toIso8601String(),
            'publish_at' => $a->publish_at?->toIso8601String(),
            'from' => $a->author?->name,
            ...($forPublisher ? [
                'status' => $a->status,
                'audience_type' => $a->audience_type,
                'audience' => $this->audienceLabel($a),
                'audience_ids' => $this->audienceUlids($a),
                'recipients_count' => $a->recipients_count,
            ] : []),
        ];
    }

    private function audienceLabel(Announcement $a): string
    {
        $ids = $a->audience_ids ?? [];

        return match ($a->audience_type) {
            'everyone' => 'Everyone',
            'staff' => 'All staff',
            'students' => 'All students',
            'roles' => collect($ids)->map(fn ($r) => SystemRole::tryFrom((string) $r)?->displayName() ?? $r)->implode(', '),
            'sections' => Section::query()->with('batch')->whereIn('id', $ids)->get()->map(fn (Section $s) => $s->batch->name.' '.$s->name)->implode(', '),
            default => self::AUDIENCE_MODELS[$a->audience_type]::query()->whereIn('id', $ids)->pluck('name')->implode(', '),
        };
    }

    /**
     * @return list<string>
     */
    private function audienceUlids(Announcement $a): array
    {
        $ids = $a->audience_ids ?? [];

        return match (true) {
            in_array($a->audience_type, ['everyone', 'staff', 'students'], true) => [],
            $a->audience_type === 'roles' => array_map('strval', $ids),
            default => self::AUDIENCE_MODELS[$a->audience_type]::query()->whereIn('id', $ids)->pluck('ulid')->all(),
        };
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
