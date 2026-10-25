<?php

namespace App\Modules\Communication\Http\Controllers;

use App\Modules\Communication\Authorization\CommunicationPermissions;
use App\Modules\Communication\Enums\NotificationChannel;
use App\Modules\Communication\Models\NotificationDelivery;
use App\Modules\Communication\Models\NotificationTemplate;
use App\Modules\Communication\Notifications\CampusNotification;
use App\Modules\Communication\Support\NotificationTemplateRegistry;
use App\Modules\Communication\Support\TemplateRenderer;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * @tags Notification templates
 */
class NotificationTemplateController extends Controller
{
    public function __construct(
        private NotificationTemplateRegistry $registry,
        private TemplateRenderer $renderer,
        private CurrentInstitution $currentInstitution,
    ) {}

    /**
     * Every notification type with its default and customised templates.
     */
    public function index(): JsonResponse
    {
        Gate::authorize(CommunicationPermissions::TemplatesManage);

        $data = collect($this->registry->all())->map(fn (string $class, string $key): array => $this->describe($key, $class))->values();

        return response()->json(['data' => $data]);
    }

    public function show(string $key): JsonResponse
    {
        Gate::authorize(CommunicationPermissions::TemplatesManage);
        $this->assertKnown($key);

        return response()->json(['data' => $this->describe($key, $this->registry->get($key))]);
    }

    /**
     * Customise a template for this institution.
     */
    public function update(Request $request, string $key, string $channel): JsonResponse
    {
        Gate::authorize(CommunicationPermissions::TemplatesManage);
        $this->assertKnown($key);
        $class = $this->registry->get($key);

        if (! array_key_exists($channel, $class::defaultTemplates())) {
            throw new BusinessRuleException('This notification is not sent on that channel.', 'CHANNEL_NOT_SUPPORTED', 422);
        }

        $validated = $request->validate([
            'subject' => [Rule::requiredIf($channel !== NotificationChannel::Sms->value), 'nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:'.($channel === NotificationChannel::Sms->value ? 480 : 10000)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $unknown = array_diff(
            $this->renderer->placeholders(($validated['subject'] ?? '').' '.$validated['body']),
            $class::placeholders(),
        );

        if ($unknown !== []) {
            throw new BusinessRuleException('Unknown placeholders: '.implode(', ', $unknown).'.', 'UNKNOWN_PLACEHOLDERS', 422, ['allowed' => $class::placeholders()]);
        }

        NotificationTemplate::query()->updateOrCreate(
            ['institution_id' => $this->currentInstitution->require()->id, 'template_key' => $key, 'channel' => NotificationChannel::from($channel)],
            [
                'subject' => $validated['subject'] ?? null,
                'body' => $validated['body'],
                'is_active' => $validated['is_active'] ?? true,
                'updated_by' => Auth::id(),
            ],
        );

        return response()->json(['data' => $this->describe($key, $class)]);
    }

    /**
     * Go back to the built-in template.
     */
    public function reset(string $key, string $channel): JsonResponse
    {
        Gate::authorize(CommunicationPermissions::TemplatesManage);
        $this->assertKnown($key);

        NotificationTemplate::query()
            ->where('institution_id', $this->currentInstitution->require()->id)
            ->where('template_key', $key)
            ->where('channel', $channel)
            ->delete();

        return response()->json(['data' => $this->describe($key, $this->registry->get($key))]);
    }

    /**
     * Delivery log for email and SMS. Filters: `filter[status]` (sent|failed), `filter[channel]`, `filter[type]`.
     */
    public function deliveries(Request $request): JsonResponse
    {
        Gate::authorize(CommunicationPermissions::DeliveriesView);

        $deliveries = NotificationDelivery::query()
            ->when($request->filled('filter.status'), fn ($query) => $query->where('status', $request->input('filter.status')))
            ->when($request->filled('filter.channel'), fn ($query) => $query->where('channel', $request->input('filter.channel')))
            ->when($request->filled('filter.type'), fn ($query) => $query->where('notification_type', $request->input('filter.type')))
            ->latest('id')
            ->cursorPaginate(min((int) $request->integer('per_page', 25), 100));

        return response()->json([
            'data' => collect($deliveries->items())->map(fn (NotificationDelivery $delivery): array => [
                'id' => $delivery->id,
                'notification_type' => $delivery->notification_type,
                'channel' => $delivery->channel,
                'status' => $delivery->status,
                'error' => $delivery->error,
                'created_at' => $delivery->created_at->toIso8601String(),
            ])->values(),
            'meta' => ['next_cursor' => $deliveries->nextCursor()?->encode(), 'per_page' => $deliveries->perPage()],
        ]);
    }

    /**
     * @param  class-string<CampusNotification>  $class
     * @return array<string, mixed>
     */
    private function describe(string $key, string $class): array
    {
        $institutionId = $this->currentInstitution->id();
        $channels = [];

        foreach ($class::defaultTemplates() as $channel => $default) {
            $custom = $this->renderer->find($key, NotificationChannel::from($channel), $institutionId);
            $isOwn = $custom !== null && $custom->institution_id === $institutionId;

            $channels[] = [
                'channel' => $channel,
                'default' => ['subject' => $default['subject'] ?? null, 'body' => $default['body']],
                'custom' => $isOwn ? ['subject' => $custom->subject, 'body' => $custom->body, 'is_active' => $custom->is_active] : null,
            ];
        }

        return [
            'key' => $key,
            'category' => $class::category()->value,
            'description' => $class::description(),
            'placeholders' => $class::placeholders(),
            'channels' => $channels,
        ];
    }

    private function assertKnown(string $key): void
    {
        if (! $this->registry->has($key)) {
            throw new BusinessRuleException('Template not found.', 'NOT_FOUND', 404);
        }
    }
}
