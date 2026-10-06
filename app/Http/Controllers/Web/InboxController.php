<?php

namespace App\Http\Controllers\Web;

use App\Enums\UserRole;
use App\Http\Controllers\Concerns\ConversationEndpoints;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Inbox\ConversationAdContext;
use App\Inbox\ConversationQuery;
use App\Inbox\HandoverDigest;
use App\Inbox\Outcomes\OutcomeRecorder;
use App\Inbox\QuickReplyCatalog;
use App\Models\City;
use App\Models\Conversation;
use App\Models\QueueSetting;
use App\Models\QuickReplyCategory;
use App\Models\Tag;
use App\Models\User;
use App\Queue\QueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class InboxController extends Controller
{
    use ConversationEndpoints;

    public function index(Request $request, ConversationQuery $query, QuickReplyCatalog $quickReplies): Response
    {
        $filters = $this->conversationFilters($request);
        $user = $request->user();

        return Inertia::render('Inbox', [
            'conversations' => ConversationResource::collection($query->paginate($user, $filters))->additional(['search_mode' => $query->searchMode(), 'meta' => ['search_truncated' => $query->searchTruncated()]]),
            // An old single `filter=` link arrives here already mapped into `flags` (R4).
            'filters' => array_merge(
                ['platform' => null, 'status' => null, 'queue' => null, 'assignee' => null, 'flags' => [], 'q' => null, 'tag' => null, 'sort' => null],
                $filters,
            ),
            // The moderator filter's options (spec §1.2): active moderators and supervisors, by name.
            'moderators' => User::query()->where('is_active', true)
                ->whereIn('role', [UserRole::Moderator->value, UserRole::Supervisor->value])
                ->orderBy('name')->get(['id', 'name', 'color'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'color' => $u->color])->values(),
            'queueEnabled' => (bool) QueueSetting::current()->enabled,
            // Control room S3: the waiting-age chip turns red past the first-reply target.
            'firstReplyTargetSeconds' => (int) QueueSetting::current()->sla_first_reply_seconds,
            'quickReplies' => $quickReplies->toArray($user),
            'quickReplyCategories' => QuickReplyCategory::orderBy('sort')->get(['id', 'name', 'sort']),
            'tags' => Tag::orderBy('name')->get(['id', 'name', 'color']),
            'cities' => City::orderBy('name_ar')->get(['id', 'name_ar', 'name_en', 'shipping_fee']),
        ]);
    }

    /** Control room S3, web only: the chat's outcome state, the bot's digest and the ad block (lazy, off the 15-query thread). */
    public function context(Request $request, Conversation $conversation, OutcomeRecorder $outcomes, HandoverDigest $digest, ConversationAdContext $ads): JsonResponse
    {
        Gate::authorize('view', $conversation);

        $entry = app(QueueService::class)->activeEntry($conversation);
        $episode = $outcomes->episode($conversation);
        $row = $outcomes->currentRow($conversation);

        return response()->json(['data' => [
            'outcome' => [
                'current' => $row?->outcome,
                'source' => $row?->source,
                'auto' => $outcomes->autoOutcome($conversation, $entry, $episode['since'], sinceKnown: true)?->value,
            ],
            'handover' => $digest->for($conversation, $entry, $episode['since']),
            'ad' => $ads->for($conversation, $request->user()),
        ]]);
    }
}
