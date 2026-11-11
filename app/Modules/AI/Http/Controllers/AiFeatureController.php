<?php

namespace App\Modules\AI\Http\Controllers;

use App\Models\User;
use App\Modules\AI\Prompts\AnnouncementWriter;
use App\Modules\AI\Prompts\ComplaintTriage;
use App\Modules\AI\Prompts\FeedbackInsights;
use App\Modules\AI\Services\AiGateway;
use App\Modules\Communication\Authorization\CommunicationPermissions;
use App\Modules\Communication\Models\Announcement;
use App\Modules\Feedback\Authorization\FeedbackPermissions;
use App\Modules\Feedback\Models\Complaint;
use App\Modules\Feedback\Models\ComplaintCategory;
use App\Modules\Feedback\Models\Survey;
use App\Modules\Feedback\Services\FeedbackService;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * AI helpers on existing screens (phase A1). Each action needs the same access as the screen it helps.
 *
 * @tags AI
 */
class AiFeatureController extends Controller
{
    public function __construct(private AiGateway $ai) {}

    /**
     * Which AI features are on, so screens can show or hide their AI buttons.
     */
    public function status(): JsonResponse
    {
        return ApiResponse::data(['configured' => $this->ai->configured()] + collect(AiGateway::FEATURES)->mapWithKeys(fn (string $f): array => [$f => $this->ai->enabled($f)])->all());
    }

    // ----- announcements ----------------------------------------------------

    /**
     * One line → title and body: `idea`, `audience?`, `tone` (friendly|formal|urgent).
     */
    public function draftAnnouncement(Request $request, AnnouncementWriter $writer): JsonResponse
    {
        $this->authorizePublishing($request);
        $v = $request->validate(['idea' => ['required', 'string', 'max:1000'], 'audience' => ['nullable', 'string', 'max:100'], 'tone' => ['required', Rule::in(['friendly', 'formal', 'urgent'])]]);
        $reply = $this->ai->run('writing', $this->user($request), fn ($m) => $writer->draft($m, $v['idea'], $v['audience'] ?? 'students and staff', $v['tone']));

        return ApiResponse::data(['title' => (string) ($reply->json['title'] ?? ''), 'body' => (string) ($reply->json['body'] ?? '')]);
    }

    /**
     * `text`, `mode` improve|shorten|formal|kannada|english.
     */
    public function rewriteAnnouncement(Request $request, AnnouncementWriter $writer): JsonResponse
    {
        $this->authorizePublishing($request);
        $v = $request->validate(['text' => ['required', 'string', 'max:8000'], 'mode' => ['required', Rule::in(AnnouncementWriter::MODES)]]);
        $reply = $this->ai->run('writing', $this->user($request), fn ($m) => $writer->rewrite($m, $v['text'], $v['mode']));

        return ApiResponse::data(['text' => (string) ($reply->json['text'] ?? '')]);
    }

    /**
     * Short summary of an announcement for whoever can read it: `language` en|kn. Cached per announcement.
     */
    public function summariseAnnouncement(Request $request, Announcement $announcement, AnnouncementWriter $writer): JsonResponse
    {
        Gate::authorize('view', $announcement);
        $language = $request->validate(['language' => ['required', Rule::in(['en', 'kn'])]])['language'];
        $key = 'ai-summary:'.$announcement->id.':'.$language.':'.md5($announcement->title.$announcement->body);

        $points = Cache::get($key) ?? tap(
            (array) ($this->ai->run('writing', $this->user($request), fn ($m) => $writer->summarise($m, $announcement->title, $announcement->body, $language))->json['points'] ?? []),
            fn (array $p) => Cache::put($key, $p, now()->addDays(30)),
        );

        return ApiResponse::data(['language' => $language, 'points' => array_values(array_map('strval', $points))]);
    }

    // ----- complaints -------------------------------------------------------

    /**
     * Suggested category and urgency while raising a complaint: `subject`, `description`.
     */
    public function suggestComplaintCategory(Request $request, ComplaintTriage $triage): JsonResponse
    {
        $v = $request->validate(['subject' => ['required', 'string', 'max:200'], 'description' => ['required', 'string', 'min:10', 'max:5000']]);
        $categories = ComplaintCategory::query()->where('status', 'active')->get(['ulid', 'name']);
        abort_if($categories->isEmpty(), 404);

        $reply = $this->ai->run('complaints', $this->user($request), fn ($m) => $triage->classify($m, $v['subject'], $v['description'], $categories->pluck('name')->all()));
        $category = $categories->firstWhere('name', $reply->json['category'] ?? null);

        return ApiResponse::data(['category_id' => $category?->ulid, 'category' => $category?->name, 'urgency' => $reply->json['urgency'] ?? 'low', 'reason' => (string) ($reply->json['reason'] ?? '')]);
    }

    /**
     * Summary, urgency and next step for the person handling a complaint.
     */
    public function triageComplaint(Request $request, Complaint $complaint, ComplaintTriage $triage, FeedbackService $feedback): JsonResponse
    {
        abort_unless($feedback->canHandle($this->user($request), $complaint), 403);
        $complaint->load(['category', 'messages']);
        $messages = $complaint->messages->where('is_internal', false)->map(fn ($m): array => ['from' => $m->user_id === $complaint->created_by ? 'Complainant' : 'Staff', 'body' => mb_substr((string) $m->body, 0, 600)])->values()->all();

        $reply = $this->ai->run('complaints', $this->user($request), fn ($m) => $triage->summarise($m, $complaint->category->name, $complaint->subject, $complaint->description, $messages));

        return ApiResponse::data(['summary' => (string) ($reply->json['summary'] ?? ''), 'urgency' => $reply->json['urgency'] ?? 'low', 'next_step' => (string) ($reply->json['next_step'] ?? '')]);
    }

    // ----- feedback ---------------------------------------------------------

    /**
     * Themes, sentiment and a summary of a survey's written comments (results viewers only).
     */
    public function surveyInsights(Request $request, Survey $survey, FeedbackInsights $insights, FeedbackService $feedback): JsonResponse
    {
        Gate::authorize(FeedbackPermissions::ResultsView);
        $results = $feedback->results($survey);

        if ($results['hidden']) {
            throw new BusinessRuleException('There are too few responses to analyse without revealing who wrote them.', 'TOO_FEW_RESPONSES', 422);
        }

        $comments = collect($results['questions'])->pluck('comments')->filter()->flatten()->map(fn ($c): string => (string) $c)->filter()->values()->all();

        if (count($comments) < 3) {
            throw new BusinessRuleException('There are not enough written comments to analyse yet.', 'TOO_FEW_COMMENTS', 422);
        }

        $reply = $this->ai->run('feedback', $this->user($request), fn ($m) => $insights->analyse($m, $survey->title, $comments));
        $json = (array) $reply->json;

        return ApiResponse::data([
            'comments' => count($comments),
            'summary' => (string) ($json['summary'] ?? ''),
            'sentiment' => ['positive' => (int) ($json['sentiment']['positive'] ?? 0), 'neutral' => (int) ($json['sentiment']['neutral'] ?? 0), 'negative' => (int) ($json['sentiment']['negative'] ?? 0)],
            'themes' => collect((array) ($json['themes'] ?? []))->map(fn ($t): array => ['theme' => (string) ($t['theme'] ?? ''), 'mentions' => (int) ($t['mentions'] ?? 0), 'tone' => (string) ($t['tone'] ?? 'mixed')])->values(),
            'suggestion' => (string) ($json['suggestion'] ?? ''),
        ]);
    }

    private function authorizePublishing(Request $request): void
    {
        abort_unless($this->user($request)->canAny([CommunicationPermissions::AnnouncementsPublish, CommunicationPermissions::AnnouncementsPublishAll]), 403);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
