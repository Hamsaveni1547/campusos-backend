<?php

namespace App\Modules\Feedback\Http\Controllers;

use App\Models\User;
use App\Modules\Feedback\Authorization\FeedbackPermissions as P;
use App\Modules\Feedback\Models\Complaint;
use App\Modules\Feedback\Models\ComplaintCategory;
use App\Modules\Feedback\Models\ComplaintMessage;
use App\Modules\Feedback\Services\FeedbackService;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * @tags Complaints
 */
class ComplaintController extends Controller
{
    public function __construct(private FeedbackService $feedback) {}

    // ----- categories ------------------------------------------------------

    public function categories(): JsonResponse
    {
        return ApiResponse::list(ComplaintCategory::query()->orderBy('name')->get(), fn (ComplaintCategory $c): array => ['id' => $c->ulid, 'name' => $c->name, 'sla_hours' => $c->sla_hours, 'is_restricted' => $c->is_restricted, 'status' => $c->status]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        return $this->saveCategory($request, null);
    }

    public function updateCategory(Request $request, ComplaintCategory $category): JsonResponse
    {
        return $this->saveCategory($request, $category);
    }

    private function saveCategory(Request $request, ?ComplaintCategory $category): JsonResponse
    {
        Gate::authorize(P::ComplaintsRestricted);
        $validated = $request->validate(['name' => ['required', 'string', 'max:100'], 'sla_hours' => ['required', 'integer', 'min:1', 'max:2160'], 'is_restricted' => ['required', 'boolean'], 'status' => ['sometimes', Rule::in(['active', 'inactive'])]]);
        $category ??= new ComplaintCategory;
        $category->fill($validated)->save();

        return ApiResponse::data(['id' => $category->ulid], $category->wasRecentlyCreated ? 201 : 200);
    }

    // ----- handling --------------------------------------------------------

    /**
     * Complaints the user can handle (restricted categories only with that permission).
     * Filters: `filter[status]`, `filter[category]`, `filter[mine]` (assigned to me), `filter[overdue]`.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($user->canAny([P::ComplaintsHandle, P::ComplaintsRestricted]), 403);
        $f = (array) $request->input('filter', []);

        $complaints = Complaint::query()->with(['category', 'raiser', 'assignee'])
            ->whereHas('category', fn ($q) => $q->whereIn('is_restricted', array_merge($user->can(P::ComplaintsHandle) ? [false] : [], $user->can(P::ComplaintsRestricted) ? [true] : [])))
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['category'] ?? null, fn ($q, $ulid) => $q->whereHas('category', fn ($c) => $c->where('ulid', $ulid)))
            ->when($f['mine'] ?? null, fn ($q) => $q->where('assigned_to', $user->id))
            ->when($f['overdue'] ?? null, fn ($q) => $q->whereIn('status', ['open', 'in_progress'])->where('due_at', '<', now()))
            ->orderByRaw("case status when 'open' then 0 when 'in_progress' then 1 when 'resolved' then 2 else 3 end")->orderBy('due_at')->paginate(25);

        return ApiResponse::paginated($complaints, fn (Complaint $c): array => $this->complaint($c, staffView: true));
    }

    public function show(Request $request, Complaint $complaint): JsonResponse
    {
        $user = $this->user($request);
        $own = $complaint->created_by === $user->id;
        abort_unless($own || $this->feedback->canHandle($user, $complaint), 403);
        $staff = ! $own;

        return ApiResponse::data([
            ...$this->complaint($complaint->load(['category', 'raiser', 'assignee']), $staff),
            'can_handle' => $staff,
            'messages' => $complaint->messages()->with('user')->when(! $staff, fn ($q) => $q->where('is_internal', false))->get()->map(fn (ComplaintMessage $m): array => [
                'body' => $m->body, 'is_internal' => $m->is_internal, 'at' => $m->created_at->toIso8601String(),
                'from' => $m->user_id === $complaint->created_by ? ($complaint->is_anonymous && $staff ? 'Complainant' : $m->user?->name) : $m->user?->name,
                'is_staff' => $m->user_id !== $complaint->created_by,
            ])->values(),
        ]);
    }

    public function assign(Request $request, Complaint $complaint): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($this->feedback->canHandle($user, $complaint), 403);
        $assignee = $request->has('user_id') ? User::query()->where('ulid', $request->validate(['user_id' => ['string', 'size:26']])['user_id'])->firstOrFail() : $user;
        $this->feedback->assign($complaint, $assignee);

        return ApiResponse::message('Assigned to '.$assignee->name.'.');
    }

    /**
     * Staff reply or internal note: `body`, `internal?`, `resolve?`.
     */
    public function respond(Request $request, Complaint $complaint): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($this->feedback->canHandle($user, $complaint), 403);
        $validated = $request->validate(['body' => ['required', 'string', 'max:5000'], 'internal' => ['sometimes', 'boolean'], 'resolve' => ['sometimes', 'boolean']]);
        $this->feedback->respond($complaint, $user, $validated['body'], (bool) ($validated['internal'] ?? false), (bool) ($validated['resolve'] ?? false));

        return ApiResponse::message(($validated['resolve'] ?? false) ? 'Marked resolved.' : 'Sent.');
    }

    // ----- self service ----------------------------------------------------

    public function mine(Request $request): JsonResponse
    {
        $complaints = Complaint::query()->with(['category', 'assignee'])->where('created_by', $this->user($request)->id)->latest('id')->get();

        return ApiResponse::list($complaints, fn (Complaint $c): array => $this->complaint($c, staffView: false));
    }

    /**
     * Raise a complaint: `category_id`, `subject`, `description`, `anonymous?`.
     */
    public function raise(Request $request): JsonResponse
    {
        $validated = $request->validate(['category_id' => ['required', 'string', 'size:26'], 'subject' => ['required', 'string', 'max:200'], 'description' => ['required', 'string', 'max:5000'], 'anonymous' => ['sometimes', 'boolean']]);
        $category = ComplaintCategory::query()->where('ulid', $validated['category_id'])->where('status', 'active')->firstOrFail();
        $complaint = $this->feedback->raise($category, $validated['subject'], $validated['description'], (bool) ($validated['anonymous'] ?? false));

        return ApiResponse::data(['id' => $complaint->ulid], 201);
    }

    public function followUp(Request $request, Complaint $complaint): JsonResponse
    {
        abort_unless($complaint->created_by === $this->user($request)->id, 403);
        $this->feedback->followUp($complaint, $this->user($request), (string) $request->validate(['body' => ['required', 'string', 'max:5000']])['body']);

        return ApiResponse::message('Sent.');
    }

    public function close(Request $request, Complaint $complaint): JsonResponse
    {
        abort_unless($complaint->created_by === $this->user($request)->id, 403);
        $this->feedback->close($complaint, (int) $request->validate(['rating' => ['required', 'integer', 'min:1', 'max:5']])['rating']);

        return ApiResponse::message('Thank you for your feedback.');
    }

    /**
     * @return array<string, mixed>
     */
    private function complaint(Complaint $c, bool $staffView): array
    {
        return [
            'id' => $c->ulid,
            'category' => ['id' => $c->category->ulid, 'name' => $c->category->name, 'is_restricted' => $c->category->is_restricted],
            'subject' => $c->subject,
            'description' => $c->description,
            'is_anonymous' => $c->is_anonymous,
            'raised_by' => $staffView && $c->is_anonymous ? null : $c->raiser?->name,
            'assigned_to' => $c->assignee?->name,
            'status' => $c->status,
            'overdue' => $c->isOverdue(),
            'due_at' => $c->due_at?->toIso8601String(),
            'raised_at' => $c->created_at?->toIso8601String(),
            'resolved_at' => $c->resolved_at?->toIso8601String(),
            'rating' => $c->rating,
        ];
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
