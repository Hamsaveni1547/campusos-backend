<?php

namespace App\Modules\AI\Http\Controllers;

use App\Models\User;
use App\Modules\AI\Assistant\Assistant;
use App\Modules\AI\Assistant\ToolRegistry;
use App\Modules\AI\Authorization\AIPermissions;
use App\Modules\AI\Models\AssistantConversation;
use App\Modules\AI\Models\AssistantMessage;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * Ask CampusOS: the person's own conversations with the assistant.
 *
 * @tags AI assistant
 */
class AssistantController extends Controller
{
    /**
     * My recent conversations, and the kinds of things I can ask.
     */
    public function index(Request $request, ToolRegistry $tools): JsonResponse
    {
        Gate::authorize(AIPermissions::AssistantUse);
        $user = $this->user($request);

        return ApiResponse::data([
            'conversations' => AssistantConversation::query()->where('user_id', $user->id)->latest('updated_at')->limit(20)->get()
                ->map(fn (AssistantConversation $c): array => ['id' => $c->ulid, 'title' => $c->title, 'updated_at' => $c->updated_at?->toIso8601String()])->values(),
            'tools' => array_keys($tools->for($user)),
        ]);
    }

    public function show(Request $request, AssistantConversation $conversation): JsonResponse
    {
        $this->authorizeOwner($request, $conversation);

        return ApiResponse::data(['id' => $conversation->ulid, 'title' => $conversation->title, 'messages' => $conversation->messages->map(fn (AssistantMessage $m): array => $this->message($m))->values()]);
    }

    /**
     * Ask a question: `message`, `conversation_id?` (continue a conversation).
     */
    public function ask(Request $request, Assistant $assistant): JsonResponse
    {
        Gate::authorize(AIPermissions::AssistantUse);
        $v = $request->validate(['message' => ['required', 'string', 'max:1000'], 'conversation_id' => ['nullable', 'string', 'size:26']]);
        $user = $this->user($request);
        $conversation = isset($v['conversation_id']) ? AssistantConversation::query()->where('ulid', $v['conversation_id'])->where('user_id', $user->id)->firstOrFail() : null;

        $result = $assistant->ask($user, $conversation, $v['message']);

        return ApiResponse::data(['conversation_id' => $result['conversation']->ulid, 'title' => $result['conversation']->title, 'message' => $this->message($result['message'])]);
    }

    public function destroy(Request $request, AssistantConversation $conversation): JsonResponse
    {
        $this->authorizeOwner($request, $conversation);
        $conversation->delete();

        return ApiResponse::message('Conversation deleted.');
    }

    private function authorizeOwner(Request $request, AssistantConversation $conversation): void
    {
        Gate::authorize(AIPermissions::AssistantUse);
        abort_unless($conversation->user_id === $this->user($request)->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function message(AssistantMessage $m): array
    {
        return ['role' => $m->role, 'content' => $m->content, 'tools' => $m->tools ?? [], 'at' => $m->created_at->toIso8601String()];
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
