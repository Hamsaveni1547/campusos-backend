<?php

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Authorization\IdentityPermissions;
use App\Modules\Identity\Http\Resources\SecurityEventResource;
use App\Modules\SystemAdmin\Authorization\SystemAdminPermissions;
use App\Shared\Audit\Activity;
use App\Shared\Audit\SecurityEvent;
use App\Shared\Audit\SecurityEventType;
use App\Shared\Audit\SecurityLogger;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @tags Audit
 */
class AuditController extends Controller
{
    private const EXPORT_LIMIT = 50000;

    public function __construct(
        private CurrentInstitution $currentInstitution,
        private SecurityLogger $securityLogger,
    ) {}

    /**
     * Audit trail of record changes. Filters: `filter[log]`, `filter[event]`, `filter[causer]` (user id),
     * `filter[subject_type]`, `filter[subject_id]`, `filter[from]`, `filter[to]` (dates).
     */
    public function activities(Request $request): JsonResponse
    {
        Gate::authorize(SystemAdminPermissions::AuditView);

        $activities = $this->activityQuery($request)
            ->with('causer')
            ->latest('id')
            ->cursorPaginate(min((int) $request->integer('per_page', 25), 100));

        return response()->json([
            'data' => collect($activities->items())->map(fn (Activity $activity): array => $this->presentActivity($activity))->values(),
            'meta' => ['next_cursor' => $activities->nextCursor()?->encode(), 'per_page' => $activities->perPage()],
        ]);
    }

    /**
     * Download the filtered audit trail as CSV (max 50,000 rows).
     */
    public function exportActivities(Request $request): StreamedResponse
    {
        Gate::authorize(SystemAdminPermissions::AuditExport);
        $this->securityLogger->log(SecurityEventType::AuditExported, null, ['filters' => $request->input('filter', [])]);

        $query = $this->activityQuery($request)->with('causer')->latest('id')->limit(self::EXPORT_LIMIT);
        $filename = 'audit-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['time', 'log', 'event', 'description', 'subject_type', 'subject_id', 'causer', 'ip_address', 'changes', 'trace_id']);

            foreach ($query->lazyById(1000, 'id') as $activity) {
                /** @var Activity $activity */
                fputcsv($out, [
                    $activity->created_at?->toIso8601String(),
                    $activity->log_name,
                    $activity->event,
                    $activity->description,
                    $activity->subject_type !== null ? class_basename($activity->subject_type) : null,
                    $activity->subject_id,
                    $activity->causer instanceof User ? $activity->causer->email : null,
                    $activity->ip_address,
                    json_encode($activity->attribute_changes),
                    $activity->trace_id,
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Security events of the institution. Filters: `filter[event]`, `filter[severity]`, `filter[user]`, `filter[from]`, `filter[to]`.
     */
    public function securityEvents(Request $request): AnonymousResourceCollection
    {
        Gate::authorize(IdentityPermissions::SecurityEventsView);
        $filters = (array) $request->input('filter', []);

        $events = SecurityEvent::query()
            ->where('institution_id', $this->currentInstitution->require()->id)
            ->when($filters['event'] ?? null, fn (Builder $query, $value) => $query->whereIn('event', (array) $value))
            ->when($filters['severity'] ?? null, fn (Builder $query, $value) => $query->where('severity', $value))
            ->when($filters['user'] ?? null, fn (Builder $query, $value) => $query->whereHas('user', fn (Builder $query) => $query->where('ulid', $value)))
            ->when($filters['from'] ?? null, fn (Builder $query, $value) => $query->where('created_at', '>=', $value))
            ->when($filters['to'] ?? null, fn (Builder $query, $value) => $query->where('created_at', '<=', $value))
            ->with(['user', 'actor'])
            ->latest('id')
            ->cursorPaginate(min((int) $request->integer('per_page', 25), 100));

        return SecurityEventResource::collection($events);
    }

    /**
     * @return Builder<Activity>
     */
    private function activityQuery(Request $request): Builder
    {
        $filters = (array) $request->input('filter', []);

        return Activity::query()
            ->where('institution_id', $this->currentInstitution->require()->id)
            ->when($filters['log'] ?? null, fn (Builder $query, $value) => $query->where('log_name', $value))
            ->when($filters['event'] ?? null, fn (Builder $query, $value) => $query->where('event', $value))
            ->when($filters['causer'] ?? null, fn (Builder $query, $value) => $query->where('causer_type', (new User)->getMorphClass())->whereIn('causer_id', User::query()->where('ulid', $value)->select('id')))
            ->when($filters['subject_type'] ?? null, fn (Builder $query, $value) => $query->where('subject_type', 'like', '%'.Str::studly((string) $value)))
            ->when($filters['subject_id'] ?? null, fn (Builder $query, $value) => $query->where('subject_id', $value))
            ->when($filters['from'] ?? null, fn (Builder $query, $value) => $query->where('created_at', '>=', $value))
            ->when($filters['to'] ?? null, fn (Builder $query, $value) => $query->where('created_at', '<=', $value));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentActivity(Activity $activity): array
    {
        return [
            'id' => $activity->id,
            'log' => $activity->log_name,
            'event' => $activity->event,
            'description' => $activity->description,
            'subject' => $activity->subject_type === null ? null : [
                'type' => Str::snake(class_basename($activity->subject_type)),
                'id' => $activity->subject_id,
            ],
            'causer' => $activity->causer instanceof User ? ['id' => $activity->causer->ulid, 'name' => $activity->causer->name] : null,
            'changes' => $activity->attribute_changes,
            'properties' => $activity->properties,
            'ip_address' => $activity->ip_address,
            'trace_id' => $activity->trace_id,
            'created_at' => $activity->created_at?->toIso8601String(),
        ];
    }
}
