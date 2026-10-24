<?php

namespace App\Http\Controllers\Concerns;

use App\Bot\BotEngine;
use App\Bot\Catalog\ProductCards;
use App\Commerce\OrderService;
use App\Enums\AttachmentStatus;
use App\Enums\ConversationPriority;
use App\Enums\ConversationStatus;
use App\Enums\OrderType;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Events\ConversationUpdated;
use App\Http\Resources\AttachmentResource;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\MessageResource;
use App\Http\Resources\NoteResource;
use App\Http\Resources\OrderResource;
use App\Http\Resources\SupportCaseResource;
use App\Http\Support\ModeratorScope;
use App\Inbox\ConversationActions;
use App\Inbox\ConversationPriorityClassifier;
use App\Inbox\ConversationQuery;
use App\Inbox\OutboundService;
use App\Inbox\Outcomes\EpisodeEnd;
use App\Inbox\Outcomes\Outcome;
use App\Inbox\Outcomes\OutcomeRecorder;
use App\Inbox\SavedReplies\AttachmentCopier;
use App\Inbox\SavedReplies\QuickReplyUsageRecorder;
use App\Inbox\SavedReplies\ReplyVariables;
use App\Inbox\SoftLock;
use App\Inbox\WindowPolicy;
use App\Media\MediaStorage;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\ProductVariant;
use App\Models\QueueSetting;
use App\Models\QuickReply;
use App\Models\QuickReplyAttachment;
use App\Models\SupportCase;
use App\Models\User;
use App\Queue\ConversationCycle;
use App\Queue\FinishRefused;
use App\Queue\QueueService;
use App\Support\SafeBroadcast;
use Closure;
use DomainException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Conversation JSON endpoints shared by the web inbox (/inbox/...) and API v1 (/api/v1/...).
 */
trait ConversationEndpoints
{
    public function list(Request $request, ConversationQuery $query): AnonymousResourceCollection
    {
        $page = $query->paginate($request->user(), $this->conversationFilters($request));

        // search_mode=like tells the client to send qmode=like with every later page of this search.
        return ConversationResource::collection($page)->additional(['search_mode' => $query->searchMode(), 'meta' => ['search_truncated' => $query->searchTruncated()]]);
    }

    /**
     * The thread detail (spec §1.3: ≤ 15 queries). `$conversation` is the raw route key, not a
     * bound model: the row is read once, with the list sub-selects (preview, direction, last human
     * reply, open case) and exists-flags that skip the notes/participants/cases queries when empty.
     * Every user the thread shows (responders, assignee, lock holder, message and note authors,
     * participants, case owners) is read in one query instead of one per relation.
     */
    public function show(Request $request, int|string $conversation, WindowPolicy $windows): JsonResponse
    {
        $conversation = ConversationQuery::withListColumns(Conversation::query()->whereKey((int) $conversation))
            ->without(['lockedBy', 'firstResponder', 'lastResponder', 'assignee'])
            ->withExists(['notes', 'participants', 'cases'])
            ->firstOrFail();

        Gate::authorize('view', $conversation);

        $messages = $conversation->messages()->with('mediaAttachments')->orderByDesc('id')->limit(50)->get()->reverse()->values();
        $notes = $conversation->notes_exists
            ? $conversation->notes()->orderByDesc('id')->limit(100)->get()
            : new EloquentCollection;
        $participantRows = $conversation->participants_exists
            ? $conversation->participants()->orderBy('first_message_at')->get()
            : new EloquentCollection;
        $cases = $conversation->cases_exists
            ? $conversation->cases()->with('order.items.variant')->latest('id')->limit(5)->get()
            : new EloquentCollection;

        $customer = $conversation->customer;
        // Nested identities/orders are platform-scoped for moderators too.
        $customer?->load(ModeratorScope::customerRelations($request->user(), orderLimit: 20, withAds: ! $request->is('api/*')));

        $this->attachUsers($conversation, $messages, $notes, $participantRows, $cases);
        foreach ($cases as $case) {
            if ($customer !== null && (int) $case->customer_id === (int) $customer->id) {
                $case->setRelation('customer', $customer);
            }
        }

        $participants = $participantRows
            ->map(fn (ConversationParticipant $p) => [
                'user' => $p->user ? ['id' => $p->user->id, 'name' => $p->user->name, 'color' => $p->user->color] : null,
                'role' => $p->role?->value,
                'messages_count' => (int) $p->messages_count,
                'first_message_at' => $p->first_message_at?->toIso8601String(),
                'last_message_at' => $p->last_message_at?->toIso8601String(),
            ])->values();

        $window = $windows->evaluate($conversation, SenderType::User);
        // SoftLock::holder()'s rule (an unexpired lock) on the holder attachUsers() already read with
        // the thread's other users — not one more users query (screen speed 2026-10-24).
        $holder = $conversation->locked_by_id !== null && $conversation->locked_until?->isFuture() ? $conversation->lockedBy : null;

        return response()->json([
            'conversation' => (new ConversationResource($conversation))->resolve($request),
            'messages' => MessageResource::collection($messages)->resolve($request),
            'notes' => NoteResource::collection($notes)->resolve($request),
            'customer' => $customer ? (new CustomerResource($customer))->resolve($request) : null,
            'participants' => $participants,
            'cases' => SupportCaseResource::collection($cases)->resolve($request),
            'window' => ['mode' => $window->mode, 'expires_at' => $window->expiresAt?->toIso8601String()],
            'lock' => [
                'holder' => $holder ? ['id' => $holder->id, 'name' => $holder->name] : null,
                'until' => $holder ? $conversation->locked_until?->toIso8601String() : null,
            ],
        ], 200, [], ConversationResource::jsonOptionsFor($request));
    }

    public function messages(Request $request, Conversation $conversation): AnonymousResourceCollection
    {
        Gate::authorize('view', $conversation);

        $data = $request->validate([
            'before_id' => ['nullable', 'integer', 'prohibits:after_id'],
            'after_id' => ['nullable', 'integer', 'prohibits:before_id'],
        ]);

        // Forward paging (catch-up after a reconnect): newer than after_id, oldest first, max 200.
        if (($after = $data['after_id'] ?? null) !== null) {
            $max = 200;
            $rows = $conversation->messages()->with(['user', 'mediaAttachments'])
                ->where('id', '>', $after)
                ->orderBy('id')
                ->limit($max + 1)
                ->get();

            return MessageResource::collection($rows->take($max)->values())
                ->additional(['has_more_after' => $rows->count() > $max]);
        }

        $limit = 50;

        $rows = $conversation->messages()->with(['user', 'mediaAttachments'])
            ->when($data['before_id'] ?? null, fn ($q, $before) => $q->where('id', '<', $before))
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        return MessageResource::collection($rows->take($limit)->reverse()->values())
            ->additional(['has_more' => $rows->count() > $limit]);
    }

    /**
     * The conversation's "الوسائط" (media) tab: every stored image/video/audio/file
     * attachment, newest first. Pending/failed/sticker rows are excluded (spec §1.5).
     */
    public function media(Request $request, Conversation $conversation): AnonymousResourceCollection
    {
        Gate::authorize('view', $conversation);

        return AttachmentResource::collection(MessageAttachment::query()
            ->whereIn('message_id', Message::query()->select('id')->where('conversation_id', $conversation->id))
            ->whereIn('type', ['image', 'video', 'audio', 'file'])
            ->where('status', AttachmentStatus::Stored->value)
            ->orderByDesc('id')->limit(200)->get());
    }

    public function uploadAttachment(Request $request, Conversation $conversation, MediaStorage $storage): JsonResponse
    {
        Gate::authorize('reply', $conversation);

        $request->validate(['file' => ['required', 'file', 'max:25600']]);

        return (new AttachmentResource($storage->storeUpload($request->file('file'), $request->user())))
            ->response()->setStatusCode(201);
    }

    public function sendMessage(Request $request, Conversation $conversation, OutboundService $outbound, QuickReplyUsageRecorder $usage): JsonResponse
    {
        Gate::authorize('reply', $conversation);

        $data = $request->validate([
            'body' => ['required_without_all:template,attachment_ids', 'nullable', 'string', 'max:4000'],
            // A template send exists precisely to reopen a closed/template-only window;
            // combining it with attachment_ids would let free-form media ride through
            // on that exception, so the two are mutually exclusive at the door.
            'template' => ['nullable', 'array', 'prohibits:attachment_ids'],
            'template.name' => ['required_with:template', 'string', 'max:255'],
            'template.language' => ['nullable', 'string', 'max:10'],
            'template.params' => ['nullable', 'array'],
            'attachment_ids' => ['nullable', 'array', 'max:'.(int) config('crm.media.max_per_message', 10), 'prohibits:template'],
            'attachment_ids.*' => ['integer', 'distinct'],
            'quick_reply_id' => ['nullable', 'integer', 'exists:quick_replies,id'],
            // Mobile outbox (contract: idempotent on client_id for 48 h): a retried send returns the stored message.
            'client_id' => ['nullable', 'uuid'],
        ]);

        // A quick_reply_id the sender isn't allowed to use — someone else's
        // personal reply, or a reply restricted to other platforms (spec
        // §2.3) — is refused before the send even runs, not silently
        // ignored — checked up front so nothing is ever recorded for it either.
        $quickReply = null;
        if (! empty($data['quick_reply_id'])) {
            $quickReply = QuickReply::findOrFail($data['quick_reply_id']);
            Gate::authorize('useIn', [$quickReply, $conversation]);
        }

        $fingerprint = [$data['body'] ?? null, $data['template'] ?? null, $data['attachment_ids'] ?? [], $data['quick_reply_id'] ?? null];

        return $this->onceForClient('msg-client', $request, $conversation, $data['client_id'] ?? null, $fingerprint,
            replay: function (array $ids) use ($request) {
                $messages = Message::query()->with(['user', 'mediaAttachments'])->whereKey($ids)->orderBy('id')->get();

                if ($messages->isEmpty()) {
                    return null;
                }

                $resource = new MessageResource($messages->first());

                return ($messages->count() > 1
                    ? $resource->additional(['messages' => MessageResource::collection($messages)->resolve($request)])
                    : $resource)->response()->setStatusCode(200);
            },
            send: fn () => $this->dispatchSend($request, $conversation, $data, $quickReply, $outbound, $usage),
        );
    }

    /**
     * The send itself (unchanged web behaviour), returning the response and the ids of
     * the messages it created so a client_id retry can find them again.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: JsonResponse, 1: list<int>}
     */
    private function dispatchSend(Request $request, Conversation $conversation, array $data, ?QuickReply $quickReply, OutboundService $outbound, QuickReplyUsageRecorder $usage): array
    {
        $options = [];

        if (! empty($data['template'])) {
            $options['template'] = [
                'name' => $data['template']['name'],
                'language' => $data['template']['language'] ?? 'ar',
                'params' => array_values($data['template']['params'] ?? []),
            ];
        }

        if (! empty($data['attachment_ids'])) {
            $messages = $outbound->sendHumanWithAttachments($conversation, $request->user(), $data['body'] ?? null, $data['attachment_ids'], $options);

            $response = (new MessageResource($messages->first()))
                ->additional(['messages' => MessageResource::collection($messages)->resolve($request)])
                ->response()->setStatusCode(201);
            $ids = $messages->pluck('id')->map(fn ($id) => (int) $id)->all();
        } else {
            $body = $data['body'] ?? ('[template] '.$data['template']['name']);

            $message = $outbound->sendHuman($conversation, $request->user(), $body, $options);

            $response = (new MessageResource($message))->response()->setStatusCode(201);
            $ids = [(int) $message->id];
        }

        // Usage is only recorded once the send actually dispatched (never
        // before — see the authorization check above, which already refused
        // the request for a reply the sender isn't allowed to use). The send
        // already succeeded at this point, so a usage-recording failure is
        // logged rather than turned into an error response for the caller.
        if ($quickReply !== null) {
            rescue(fn () => $usage->record($quickReply, $request->user(), $conversation), report: true);
        }

        return [$response, $ids];
    }

    /**
     * Idempotent sends for the mobile outbox (API contract, POST messages / notes): with a
     * client_id, the first send stores the created ids under
     * `{prefix}:{user}:{conversation}:{client_id}` for crm.mobile.client_id_ttl_hours; a
     * retry with the same key returns the stored resource with 200 instead of sending again.
     * The send runs under a Cache::lock so two racing retries cannot both send. Without a
     * client_id nothing changes. No schema change: the cache is the record. The same
     * client_id with a different request (body, attachments, …) is refused with 409, never
     * silently answered with the old message (review 01, M6).
     *
     * @param  array<int, mixed>  $fingerprint  what makes two requests "the same send"
     * @param  Closure(list<int>): ?JsonResponse  $replay  null when the stored rows are gone (then it sends again)
     * @param  Closure(): array{0: JsonResponse, 1: list<int>}  $send
     */
    private function onceForClient(string $prefix, Request $request, Conversation $conversation, ?string $clientId, array $fingerprint, Closure $replay, Closure $send): JsonResponse
    {
        if ($clientId === null || $clientId === '') {
            return $send()[0];
        }

        $key = $prefix.':'.$request->user()->id.':'.$conversation->id.':'.strtolower($clientId);

        try {
            $hash = sha1((string) json_encode($fingerprint));

            // 60 s: longer than any synchronous send (the provider call itself is queued).
            return Cache::lock($key.':lock', 60)->block(5, function () use ($key, $hash, $replay, $send) {
                $stored = Cache::get($key);
                $ids = is_array($stored) ? (array) ($stored['ids'] ?? []) : [];

                if ($ids !== [] && ($stored['hash'] ?? null) !== $hash) {
                    return response()->json(['message' => __('errors.send.client_id_reused')], 409);
                }

                if ($ids !== [] && ($replayed = $replay(array_map('intval', $ids))) !== null) {
                    return $replayed;
                }

                [$response, $created] = $send();
                Cache::put($key, ['ids' => $created, 'hash' => $hash], now()->addHours((int) config('crm.mobile.client_id_ttl_hours', 48)));

                return $response;
            });
        } catch (LockTimeoutException) {
            // The same client_id is still being sent by another request: ask the outbox to retry shortly.
            return response()->json(['message' => __('Too Many Attempts.')], 429, ['Retry-After' => '2']);
        }
    }

    /**
     * «ابعت منتج» (2026-10-17): the picked variant as the bot's product card. A variant that is
     * out of stock is refused until the moderator confirms (`confirm_out_of_stock`).
     */
    public function sendProduct(Request $request, Conversation $conversation, OutboundService $outbound, ProductCards $productCards): JsonResponse
    {
        Gate::authorize('reply', $conversation);

        $data = $request->validate([
            'variant_id' => ['required', 'integer'],
            'confirm_out_of_stock' => ['nullable', 'boolean'],
        ]);

        $variant = ProductVariant::query()->with('product')->whereKey($data['variant_id'])
            ->whereHas('product', fn ($p) => $p->where('status', 'active'))
            ->first();

        if ($variant === null) {
            throw ValidationException::withMessages(['variant_id' => __('errors.inbox.product_unavailable')]);
        }

        if (! ProductCards::variantInStock($variant) && ! ($data['confirm_out_of_stock'] ?? false)) {
            return response()->json(['message' => __('errors.inbox.product_out_of_stock'), 'code' => 'out_of_stock'], 409);
        }

        $card = $productCards->variantCard($variant);
        $message = $outbound->sendHumanCards($conversation, $request->user(), $card['text'], $card['cards']);

        return (new MessageResource($message))->response()->setStatusCode(201);
    }

    /**
     * Renders a saved reply's variables against this conversation and copies
     * its attachments into fresh, unlinked outbound uploads (spec §2.2).
     * Nothing is sent — the caller decides what to do with the result.
     */
    public function renderQuickReply(Request $request, Conversation $conversation, QuickReply $quickReply, ReplyVariables $variables, AttachmentCopier $copier): JsonResponse
    {
        Gate::authorize('reply', $conversation);
        Gate::authorize('useIn', [$quickReply, $conversation]);

        $rendered = $variables->render($quickReply->body, $conversation->loadMissing('customer'), $request->user());
        $attachments = $quickReply->attachments->map(fn (QuickReplyAttachment $a) => $copier->toOutbound($a, $request->user()));

        return response()->json([
            'body' => $rendered->body,
            'attachments' => AttachmentResource::collection($attachments)->resolve($request),
            'missing' => $rendered->missing,
        ]);
    }

    public function retryMessage(Request $request, Message $message, OutboundService $outbound): MessageResource
    {
        Gate::authorize('reply', $message->conversation);

        try {
            $message = $outbound->retry($message, $request->user());
        } catch (DomainException $e) {
            // OutboundService::retry() only retries failed outbound messages.
            abort(response()->json(['message' => $e->getMessage()], 422));
        }

        return new MessageResource($message->load(['user', 'mediaAttachments']));
    }

    public function typing(Request $request, Conversation $conversation, SoftLock $lock): JsonResponse
    {
        Gate::authorize('reply', $conversation);

        // `locked` = the caller now holds the soft lock; `holder` = whoever holds it.
        $locked = $lock->acquire($conversation, $request->user());
        $holder = $lock->holder($conversation->refresh());

        return response()->json([
            'locked' => $locked,
            'holder' => $holder ? ['id' => $holder->id, 'name' => $holder->name] : null,
        ]);
    }

    public function storeNote(Request $request, Conversation $conversation, ConversationActions $actions): JsonResponse
    {
        Gate::authorize('view', $conversation);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'mentions' => ['nullable', 'array', 'max:20'],
            'mentions.*' => ['integer'],
            'client_id' => ['nullable', 'uuid'],
        ]);

        return $this->onceForClient('note-client', $request, $conversation, $data['client_id'] ?? null, [$data['body'], $data['mentions'] ?? []],
            replay: function (array $ids) {
                $note = ConversationNote::query()->with('user')->find($ids[0] ?? 0);

                return $note ? (new NoteResource($note))->response()->setStatusCode(200) : null;
            },
            send: function () use ($request, $conversation, $actions, $data) {
                $note = $actions->addNote($conversation, $request->user(), $data['body'], $data['mentions'] ?? []);

                return [(new NoteResource($note))->response()->setStatusCode(201), [(int) $note->id]];
            },
        );
    }

    /**
     * Explicit "استلام" claim (spec §5.4, Task 15): any user who can reply may take
     * a forced soft lock, taking over from any current holder. Never blocks sending.
     */
    public function claim(Request $request, Conversation $conversation, SoftLock $lock): ConversationResource
    {
        Gate::authorize('reply', $conversation);

        $lock->claim($conversation, $request->user());

        return new ConversationResource($this->listRow($conversation));
    }

    /**
     * @mentions autocomplete data source (Task 15): active users who can access
     * this conversation's platform — id/name/color only, never phone numbers.
     */
    public function mentionable(Request $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);

        $users = User::query()->where('is_active', true)->inboxStaff()->with('userPlatforms')->orderBy('name')->get()
            ->filter(fn (User $u) => $u->canAccessPlatform($conversation->platform))
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'color' => $u->color])->values();

        return response()->json(['data' => $users]);
    }

    public function resolve(Request $request, Conversation $conversation, ConversationActions $actions, OutcomeRecorder $outcomes): ConversationResource
    {
        Gate::authorize('reply', $conversation);
        $this->guardQueueWindow($request->user(), $conversation);

        // D16: the mobile app sends no body; there the outcome is optional and recorded `unknown`.
        $api = $request->is('api/*');
        $data = $request->validate([
            'outcome' => ['nullable', 'string', Rule::in(Outcome::agentValues())],
            'outcome_note' => ['nullable', 'required_if:outcome,other', 'string', 'max:120'],
        ], [
            'outcome.in' => __('errors.outcome.required'),
            'outcome_note.required_if' => __('errors.outcome.note_required'),
        ]);
        $outcome = isset($data['outcome']) ? Outcome::from($data['outcome']) : null;

        // D13: on the web a resolve needs the chat's outcome unless it is automatic or the chat is already resolved.
        if (! $api && $outcome === null && $conversation->status !== ConversationStatus::Resolved
            && $outcomes->autoOutcome($conversation, app(QueueService::class)->activeEntry($conversation)) === null) {
            throw ValidationException::withMessages(['outcome' => __('errors.outcome.required')]);
        }

        // Close cycle §5.2: an open queue window ends through «خلصت» (D-11: a real close with the thanks
        // and her points, never a silent `resolved_elsewhere`); everything else resolves as before (RC-12, H-18).
        if ($this->closesWindow($conversation, ['after' => 'thanks', 'outcome' => $outcome, 'outcome_note' => $data['outcome_note'] ?? null, 'default_outcome' => Outcome::Unknown], $request->user())) {
            return new ConversationResource($this->listRow($conversation));
        }

        $resolved = $actions->resolve($conversation, $request->user(), $outcome, $data['outcome_note'] ?? null, $api ? EpisodeEnd::ApiResolve : EpisodeEnd::Resolve);

        return new ConversationResource($this->listRow($resolved));
    }

    /**
     * Handover queue on: a conversation with an OPEN window (called / active) is resolved or
     * returned to the bot by its moderator, a supervisor or an admin only (the same rule as the
     * queue's own endpoints; web, API and mobile). A customer still waiting in the lounge has no
     * owner yet: anybody who may reply may take her out. Queue off: nothing is read.
     */
    private function guardQueueWindow(User $user, Conversation $conversation): void
    {
        if ($user->isSupervisorOrAbove() || ! QueueSetting::current()->enabled) {
            return;
        }

        $entry = app(QueueService::class)->activeEntry($conversation);

        if ($entry !== null && $entry->isOpen() && (int) $entry->assigned_user_id !== (int) $user->id) {
            abort(response()->json(['message' => __('errors.queue.not_your_window')], 403));
        }
    }

    public function reopen(Request $request, Conversation $conversation, ConversationActions $actions): JsonResponse
    {
        Gate::authorize('reply', $conversation);

        // Close cycle §7.3: kept for the installed app (same body); the web no longer offers it — a reply is the claim.
        return (new ConversationResource($this->listRow($actions->reopen($conversation, $request->user()))))
            ->response($request)->header('Deprecation', 'true');
    }

    public function returnToBot(Request $request, Conversation $conversation, BotEngine $bot): ConversationResource
    {
        Gate::authorize('reply', $conversation);
        $this->guardQueueWindow($request->user(), $conversation);

        // Close cycle §5.2 (D-10): on an open window «رجوع للبوت» is «خلصت» with «البوت يكمّل» — her outcome
        // (else the automatic one, else `unknown`: H-14) and her points, no message. Otherwise as before.
        if (! $this->closesWindow($conversation, ['after' => 'bot', 'default_outcome' => Outcome::Unknown], $request->user())) {
            $bot->returnToBot($conversation, $request->user());
        }

        return new ConversationResource($this->listRow($conversation));
    }

    /**
     * The old endpoints' way into «خلصت» (close-cycle-FINAL §5.2): only for an OPEN window of the queue
     * (on); false when there is none, and the caller does what it always did. She waiting for a reply
     * never refuses (the old app knows no `customer_waiting`): the close goes, with no closing message.
     */
    private function closesWindow(Conversation $conversation, array $r, User $user): bool
    {
        if (! QueueSetting::current()->enabled) {
            return false;
        }

        $entry = app(QueueService::class)->activeEntry($conversation);

        if ($entry === null || ! $entry->isOpen()) {
            return false;
        }

        try {
            app(ConversationCycle::class)->finish($conversation, $user, $r + ['entry_id' => $entry->id, 'confirm_waiting' => true, 'no_amend' => true]);
        } catch (FinishRefused $x) {
            if ($x->key === 'outcome_required') {
                throw ValidationException::withMessages(['outcome' => __('errors.outcome.required')]);
            }
            // The window closed or changed between our read and the lock: the old endpoint does what it always did.
            if (in_array($x->key, ['stale_state', 'already_closed'], true)) {
                return false;
            }
            abort(response()->json(['message' => __('errors.queue.'.$x->key), 'code' => $x->key], $x->status));
        }

        return true;
    }

    public function reset(Request $request, Conversation $conversation, ConversationActions $actions): ConversationResource
    {
        Gate::authorize('reset', $conversation);

        $actions->reset($conversation, $request->user());

        return new ConversationResource($this->listRow($conversation));
    }

    public function read(Request $request, Conversation $conversation, ConversationActions $actions): ConversationResource
    {
        Gate::authorize('view', $conversation);

        return new ConversationResource($this->listRow($actions->markRead($conversation)));
    }

    public function syncTags(Request $request, Conversation $conversation, ConversationActions $actions): ConversationResource
    {
        Gate::authorize('reply', $conversation);

        $data = $request->validate([
            'tag_ids' => ['present', 'array'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
        ]);

        return new ConversationResource($this->listRow($actions->syncTags($conversation, $data['tag_ids'])));
    }

    /**
     * Moderator override: "مش سبام" / "مهمة" in the thread header, and the same
     * action from the settings screen. Authorization matches sending a message.
     */
    public function setPriority(Request $request, Conversation $conversation, ConversationPriorityClassifier $classifier): ConversationResource
    {
        Gate::authorize('reply', $conversation);

        $data = $request->validate([
            'priority' => ['required', Rule::enum(ConversationPriority::class)],
        ]);

        $classifier->setManually($conversation, ConversationPriority::from($data['priority']), $request->user());

        $conversation = $this->listRow($conversation);
        SafeBroadcast::send(new ConversationUpdated($conversation));

        return new ConversationResource($conversation);
    }

    public function storeOrder(Request $request, Conversation $conversation, OrderService $orders): JsonResponse
    {
        Gate::authorize('reply', $conversation);

        // `idempotency_key` is required (Task 9 ruling): the web drawer and mobile
        // sheet generate one uuid per open sheet/drawer and reuse it on retry.
        // API v1 compatibility window (final fix wave I4, one release): older
        // mobile builds send no key, so the API accepts it missing (OrderService
        // generates a UUID) and logs it; a present key must still be a uuid.
        // Remove only after the updated mobile app is deployed.
        // `shipping.city_id` stays accepted for legacy callers alongside the
        // province/rate shape.
        $apiCompatibilityWindow = $request->is('api/v1/*');

        $data = $request->validate([
            'idempotency_key' => [$apiCompatibilityWindow ? 'nullable' : 'required', 'uuid'],
            'type' => ['required', Rule::enum(OrderType::class)],
            'items' => ['present', 'array'],
            'items.*.variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:999'],
            'shipping' => ['nullable', 'array'],
            'shipping.name' => ['nullable', 'string', 'max:255'],
            'shipping.phone' => ['nullable', 'string', 'max:50'],
            'shipping.city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'shipping.address' => ['nullable', 'string', 'max:500'],
            'shipping.address1' => ['nullable', 'string', 'max:500'],
            'shipping.city' => ['nullable', 'string', 'max:255'],
            'shipping.province_code' => ['nullable', 'string', 'max:10'],
            'shipping.address_id' => ['nullable', 'integer', 'exists:customer_addresses,id'],
            'shipping.rate_id' => ['nullable', 'integer', 'exists:shipping_rates,id'],
            'shipping_rate_id' => ['nullable', 'integer', 'exists:shipping_rates,id'],
            'province_code' => ['nullable', 'string', 'max:10'],
            'address_id' => ['nullable', 'integer', 'exists:customer_addresses,id'],
            'discount' => ['nullable', 'numeric', 'min:0', Rule::when($request->input('discount_type') === 'percent', ['max:100'])],
            'discount_type' => ['nullable', Rule::in(['fixed', 'percent'])],
            'discount_reason' => [
                Rule::requiredIf(fn () => $request->filled('discount_type') && (float) $request->input('discount', 0) > 0),
                'nullable', 'string', 'max:255',
            ],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($apiCompatibilityWindow && blank($data['idempotency_key'] ?? null)) {
            Log::channel('stack')->warning('api_v1_order_without_idempotency_key', ['user_id' => $request->user()?->id]);
        }

        $shipping = $data['shipping'] ?? [];

        foreach (['shipping_rate_id' => 'rate_id', 'province_code' => 'province_code', 'address_id' => 'address_id'] as $flat => $nested) {
            if (isset($data[$flat]) && empty($shipping[$nested])) {
                $shipping[$nested] = $data[$flat];
            }
        }

        $discount = $request->filled('discount_type') || $request->filled('discount_reason')
            ? ['type' => $data['discount_type'] ?? 'fixed', 'value' => (float) ($data['discount'] ?? 0), 'reason' => $data['discount_reason'] ?? null]
            : ($data['discount'] ?? null);

        try {
            $order = $orders->create($conversation, $request->user(), [
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'type' => $data['type'],
                'items' => $data['items'],
                'shipping' => $shipping,
                'discount' => $discount,
                'note' => $data['note'] ?? null,
            ]);
        } catch (DomainException $e) {
            if ($e->getMessage() !== 'idempotency_conflict') {
                throw $e;
            }

            abort(response()->json(['message' => __('errors.orders.idempotency_conflict')], 409));
        }

        return (new OrderResource($order->loadMissing(['items', 'createdBy', 'customer'])))
            ->response()->setStatusCode($order->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * GET conversations/counts: per-state counts for the filter bar under the other list params
     * (status/queue ignored), each capped (see ConversationQuery::counts), cached 15 s per user + params.
     */
    public function counts(Request $request, ConversationQuery $query): JsonResponse
    {
        $user = $request->user();
        $params = $this->conversationFilters($request);
        unset($params['status'], $params['queue'], $params['qmode'], $params['sort']);
        ksort($params);

        $counts = Cache::remember(
            'inbox.counts.'.$user->id.'.'.md5((string) json_encode($params)),
            15,
            fn () => $query->counts($user, $params),
        );

        return response()->json($counts);
    }

    /**
     * A single conversation as the list shows it: the list sub-selects and eager loads, so every
     * single-conversation response matches a list row and ConversationResource runs no extra query.
     */
    protected function listRow(Conversation $conversation): Conversation
    {
        return ConversationQuery::withListColumns(Conversation::query()->whereKey($conversation->getKey()))->firstOrFail();
    }

    /**
     * Reads every user the thread detail shows in one query and sets the relations.
     *
     * @param  Collection<int, Message>  $messages
     * @param  Collection<int, ConversationNote>  $notes
     * @param  Collection<int, ConversationParticipant>  $participants
     * @param  Collection<int, SupportCase>  $cases
     */
    private function attachUsers(Conversation $conversation, Collection $messages, Collection $notes, Collection $participants, Collection $cases): void
    {
        $conversationKeys = ['lockedBy' => 'locked_by_id', 'firstResponder' => 'first_responder_id', 'lastResponder' => 'last_responder_id', 'assignee' => 'assignee_id'];

        $ids = collect(array_map(fn (string $col) => $conversation->{$col}, $conversationKeys))
            ->merge($messages->pluck('user_id'))->merge($notes->pluck('user_id'))
            ->merge($participants->pluck('user_id'))->merge($cases->pluck('assigned_to_id'))
            ->filter()->map(fn ($id) => (int) $id)->unique()->values();

        $users = $ids->isEmpty() ? collect() : User::query()->whereIn('id', $ids)->get()->keyBy('id');
        $find = fn ($id) => $id !== null ? $users->get((int) $id) : null;

        foreach ($conversationKeys as $relation => $col) {
            $conversation->setRelation($relation, $find($conversation->{$col}));
        }
        $messages->each(fn (Message $m) => $m->setRelation('user', $find($m->user_id)));
        $notes->each(fn (ConversationNote $n) => $n->setRelation('user', $find($n->user_id)));
        $participants->each(fn (ConversationParticipant $p) => $p->setRelation('user', $find($p->user_id)));
        $cases->each(fn (SupportCase $c) => $c->setRelation('assignedTo', $find($c->assigned_to_id)));
    }

    /**
     * List params (spec §1.2). `flags` is returned as a list with the legacy single `filter` merged in.
     *
     * @return array{platform?: ?string, status?: ?string, queue?: ?string, assignee?: ?string, flags: list<string>, q?: ?string, tag?: ?int}
     */
    protected function conversationFilters(Request $request): array
    {
        $data = $request->validate([
            'platform' => ['nullable', Rule::enum(Platform::class)],
            'status' => ['nullable', Rule::in(ConversationQuery::STATUSES)],
            'queue' => ['nullable', Rule::in(ConversationQuery::QUEUE_STATES)],
            'assignee' => ['nullable', 'regex:/^(me|none|\d+)$/'],
            'flags' => ['nullable', 'string', 'max:300'],
            'filter' => ['nullable', Rule::in(ConversationQuery::FILTERS)],
            'q' => ['nullable', 'string', 'max:100'],
            'qmode' => ['nullable', Rule::in(['like'])],
            'tag' => ['nullable', 'integer', 'exists:tags,id'],
            'sort' => ['nullable', Rule::in(ConversationQuery::SORTS)],
            // Control room S4, optional and additive (API v1 callers that omit them see no change): chats
            // that started on these Cairo days.
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $flags = ConversationQuery::flagsOf(['flags' => $data['flags'] ?? null, 'filter' => $data['filter'] ?? null]);
        $unknown = array_diff($flags, ConversationQuery::FILTERS);
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'flags' => __('validation.in', ['attribute' => 'flags']).' ('.implode(', ', $unknown).')',
            ]);
        }

        unset($data['filter']);
        $data['flags'] = $flags;

        return array_filter($data, fn ($v) => $v !== null);
    }
}
