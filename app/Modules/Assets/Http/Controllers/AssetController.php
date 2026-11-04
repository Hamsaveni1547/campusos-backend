<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Models\User;
use App\Modules\Assets\Authorization\AssetPermissions as P;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\MaintenanceRequest;
use App\Modules\Assets\Models\RoomBooking;
use App\Modules\Assets\Services\AssetService;
use App\Modules\HR\Models\Employee;
use App\Modules\Institution\Models\Department;
use App\Modules\Institution\Models\Room;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Http\ApiResponse;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * @tags Labs and assets
 */
class AssetController extends Controller
{
    public function __construct(private AssetService $assets) {}

    // ----- asset register --------------------------------------------------

    /**
     * Assets. Filters: `filter[search]` (tag, name, serial), `filter[category]`, `filter[status]`, `filter[room]`, `filter[warranty]` (expiring in 60 days).
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(P::ReportsView);
        $f = (array) $request->input('filter', []);

        $assets = Asset::query()->with(['room', 'department'])
            ->when($f['search'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->where('tag', 'like', "%{$t}%")->orWhere('name', 'like', "%{$t}%")->orWhere('serial_no', 'like', "%{$t}%")))
            ->when($f['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['room'] ?? null, fn ($q, $ulid) => $q->whereHas('room', fn ($r) => $r->where('ulid', $ulid)))
            ->when($f['warranty'] ?? null, fn ($q) => $q->whereBetween('warranty_until', [today(), today()->addDays(60)]))
            ->orderBy('tag')->paginate(25);

        return ApiResponse::paginated($assets, fn (Asset $a): array => $this->asset($a));
    }

    /**
     * Counts by status and category, total value, and warranties ending soon.
     */
    public function summary(): JsonResponse
    {
        Gate::authorize(P::ReportsView);
        $assets = Asset::query()->get(['status', 'category', 'cost', 'warranty_until']);

        return ApiResponse::data([
            'total' => $assets->count(),
            'value' => round((float) $assets->where('status', '!=', 'condemned')->sum('cost'), 2),
            'by_status' => $assets->countBy('status')->all(),
            'by_category' => $assets->countBy('category')->sortKeys()->all(),
            'warranty_expiring' => $assets->filter(fn (Asset $a): bool => $a->warranty_until !== null && $a->warranty_until->between(today(), today()->addDays(60)))->count(),
            'open_requests' => MaintenanceRequest::query()->whereIn('status', ['open', 'in_progress'])->count(),
            'categories' => $assets->pluck('category')->unique()->sort()->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(P::Manage);
        $asset = Asset::query()->create($this->validated($request, null))->refresh();

        return ApiResponse::data($this->asset($asset->load(['room', 'department'])), 201);
    }

    public function update(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize(P::Manage);
        $asset->update($this->validated($request, $asset));

        return ApiResponse::data($this->asset($asset->load(['room', 'department'])));
    }

    /**
     * An asset with its maintenance history.
     */
    public function show(Asset $asset): JsonResponse
    {
        Gate::authorize(P::ReportsView);

        return ApiResponse::data([
            ...$this->asset($asset->load(['room', 'department'])),
            'requests' => MaintenanceRequest::query()->with('raiser')->where('asset_id', $asset->id)->latest('id')->get()->map(fn (MaintenanceRequest $r): array => $this->request($r))->values(),
        ]);
    }

    // ----- maintenance -----------------------------------------------------

    /**
     * Requests: staff handling maintenance see all; others see the ones they raised. Filter: `filter[status]`.
     */
    public function requests(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $status = $request->input('filter.status');

        $requests = MaintenanceRequest::query()->with(['asset', 'room', 'raiser'])
            ->when(! $user->canAny([P::MaintenanceManage, P::ReportsView]), fn ($q) => $q->where('created_by', $user->id))
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->orderByRaw("case priority when 'high' then 0 when 'medium' then 1 else 2 end")->latest('id')->paginate(25);

        return ApiResponse::paginated($requests, fn (MaintenanceRequest $r): array => $this->request($r));
    }

    /**
     * Raise a request (any staff member): `title`, `description?`, `priority`, `asset_id?` (tag or id), `room_id?`.
     */
    public function raise(Request $request): JsonResponse
    {
        abort_unless(Employee::query()->where('user_id', $this->user($request)->id)->exists() || $this->user($request)->can(P::MaintenanceManage), 403);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'priority' => ['required', Rule::in(['low', 'medium', 'high'])],
            'asset' => ['nullable', 'string', 'max:30'],
            'room_id' => ['nullable', 'string', 'size:26'],
        ]);
        $asset = isset($validated['asset']) ? (Asset::query()->where('tag', $validated['asset'])->orWhere('ulid', $validated['asset'])->first() ?? throw new BusinessRuleException('No asset with tag '.$validated['asset'].'.', 'ASSET_NOT_FOUND', 422)) : null;
        $roomId = isset($validated['room_id']) ? Room::query()->where('ulid', $validated['room_id'])->value('id') : $asset?->room_id;

        $maintenance = MaintenanceRequest::query()->create(['asset_id' => $asset?->id, 'room_id' => $roomId, 'title' => $validated['title'], 'description' => $validated['description'] ?? null, 'priority' => $validated['priority']]);
        $maintenance->forceFill(['status' => 'open'])->save();

        return ApiResponse::data(['id' => $maintenance->ulid], 201);
    }

    /**
     * Move a request on: `status` (in_progress|resolved|closed), `assigned_to?`, `resolution?`, `cost?`.
     */
    public function updateRequest(Request $request, MaintenanceRequest $maintenance): JsonResponse
    {
        Gate::authorize(P::MaintenanceManage);
        $validated = $request->validate([
            'status' => ['required', Rule::in(['in_progress', 'resolved', 'closed'])],
            'assigned_to' => ['nullable', 'string', 'max:150'],
            'resolution' => ['nullable', 'string', 'max:2000'],
            'cost' => ['nullable', 'numeric', 'min:0'],
        ]);
        $this->assets->updateRequest($maintenance, $validated['status'], [
            'assigned_to' => $validated['assigned_to'] ?? null, 'resolution' => $validated['resolution'] ?? null, 'cost' => isset($validated['cost']) ? (float) $validated['cost'] : null,
        ]);

        return ApiResponse::data($this->request($maintenance->refresh()->load(['asset', 'room', 'raiser'])));
    }

    // ----- bookings --------------------------------------------------------

    /**
     * Bookings from today. Filters: `filter[room]`, `filter[mine]` (1).
     */
    public function bookings(Request $request): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($user->canAny([P::RoomsBook, P::ReportsView]), 403);
        $f = (array) $request->input('filter', []);

        $bookings = RoomBooking::query()->with(['room', 'booker'])->where('status', 'booked')->whereDate('date', '>=', today())
            ->when($f['room'] ?? null, fn ($q, $ulid) => $q->whereHas('room', fn ($r) => $r->where('ulid', $ulid)))
            ->when($f['mine'] ?? null, fn ($q) => $q->where('created_by', $user->id))
            ->orderBy('date')->orderBy('starts_at')->paginate(25);

        return ApiResponse::paginated($bookings, fn (RoomBooking $b): array => [
            'id' => $b->ulid, 'room' => ['id' => $b->room->ulid, 'code' => $b->room->code, 'name' => $b->room->name],
            'date' => $b->date->toDateString(), 'starts_at' => substr($b->starts_at, 0, 5), 'ends_at' => substr($b->ends_at, 0, 5),
            'purpose' => $b->purpose, 'booked_by' => $b->booker?->name, 'can_cancel' => $b->created_by === $user->id || $user->can(P::Manage),
        ]);
    }

    /**
     * Book a lab or room: `room_id`, `date`, `starts_at`, `ends_at`, `purpose`.
     */
    public function book(Request $request): JsonResponse
    {
        Gate::authorize(P::RoomsBook);
        $validated = $request->validate([
            'room_id' => ['required', 'string', 'size:26'],
            'date' => ['required', 'date'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
            'purpose' => ['required', 'string', 'max:300'],
        ]);
        $room = Room::query()->where('ulid', $validated['room_id'])->firstOrFail();
        $booking = $this->assets->book($room, Carbon::parse($validated['date']), $validated['starts_at'], $validated['ends_at'], $validated['purpose']);

        return ApiResponse::data(['id' => $booking->ulid], 201);
    }

    public function cancelBooking(Request $request, RoomBooking $booking): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($booking->created_by === $user->id || $user->can(P::Manage), 403);
        $booking->forceFill(['status' => 'cancelled'])->save();

        return ApiResponse::message('Booking cancelled.');
    }

    // ----- helpers ---------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Asset $asset): array
    {
        $validated = $request->validate([
            'tag' => ['required', 'string', 'max:30', Rule::unique('assets', 'tag')->where('institution_id', app(CurrentInstitution::class)->id())->ignore($asset?->id)],
            'name' => ['required', 'string', 'max:150'],
            'category' => ['required', 'string', 'max:50'],
            'department_id' => ['nullable', 'string', 'size:26'],
            'room_id' => ['nullable', 'string', 'size:26'],
            'make' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'serial_no' => ['nullable', 'string', 'max:100'],
            'purchased_on' => ['nullable', 'date'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'vendor' => ['nullable', 'string', 'max:150'],
            'warranty_until' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in(Asset::STATUSES)],
        ]);
        $validated['department_id'] = isset($validated['department_id']) ? Department::query()->where('ulid', $validated['department_id'])->value('id') : null;
        $validated['room_id'] = isset($validated['room_id']) ? Room::query()->where('ulid', $validated['room_id'])->value('id') : null;

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function asset(Asset $a): array
    {
        return [
            'id' => $a->ulid, 'tag' => $a->tag, 'name' => $a->name, 'category' => $a->category,
            'department' => $a->department === null ? null : ['id' => $a->department->ulid, 'name' => $a->department->name],
            'room' => $a->room === null ? null : ['id' => $a->room->ulid, 'code' => $a->room->code, 'name' => $a->room->name],
            'make' => $a->make, 'model' => $a->model, 'serial_no' => $a->serial_no,
            'purchased_on' => $a->purchased_on?->toDateString(), 'cost' => $a->cost === null ? null : (float) $a->cost, 'vendor' => $a->vendor,
            'warranty_until' => $a->warranty_until?->toDateString(), 'status' => $a->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function request(MaintenanceRequest $r): array
    {
        return [
            'id' => $r->ulid, 'title' => $r->title, 'description' => $r->description, 'priority' => $r->priority, 'status' => $r->status,
            'asset' => $r->asset === null ? null : ['id' => $r->asset->ulid, 'tag' => $r->asset->tag, 'name' => $r->asset->name],
            'room' => $r->room === null ? null : ['code' => $r->room->code, 'name' => $r->room->name],
            'assigned_to' => $r->assigned_to, 'resolution' => $r->resolution, 'cost' => $r->cost === null ? null : (float) $r->cost,
            'raised_by' => $r->raiser?->name, 'raised_at' => $r->created_at?->toIso8601String(), 'resolved_at' => $r->resolved_at?->toIso8601String(),
        ];
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
