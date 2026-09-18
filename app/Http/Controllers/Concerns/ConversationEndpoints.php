<?php

namespace App\Http\Controllers\Concerns;

use App\Bot\BotEngine;
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
use App\Inbox\SavedReplies\AttachmentCopier;
use App\Inbox\SavedReplies\QuickReplyUsageRecorder;
use App\Inbox\SavedReplies\ReplyVariables;
use App\Inbox\SoftLock;
use App\Inbox\WindowPolicy;
use App\Media\MediaStorage;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\QuickReply;
use App\Models\QuickReplyAttachment;
use App\Models\User;
use App\Support\SafeBroadcast;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Conversation JSON endpoints shared by the web inbox (/inbox/...) and API v1 (/api/v1/...).
 */
trait ConversationEndpoints
{
    public function list(Request $request, ConversationQuery $query): AnonymousResourceCollection
    {
        return ConversationResource::collection($query->paginate($request->user(), $this->conversationFilters($request)));
    }

    public function show(Request $request, Conversation $conversation, WindowPolicy $windows, SoftLock $lock): JsonResponse
    {
        Gate::authorize('view', $conversation);

        $conversation->load(['customer', 'lockedBy', 'firstResponder', 'lastResponder', 'tags']);

        $messages = $conversation->messages()->with(['user', 'mediaAttachments'])->orderByDesc('id')->limit(50)->get()->reverse()->values();
        $notes = $conversation->notes()->with('user')->orderByDesc('id')->get();

        $customer = $conversation->customer;
        // Nested identities/orders are platform-scoped for moderators too.
        $customer?->load(ModeratorScope::customerRelations($request->user(), orderLimit: 20));

        $participants = $conversation->participants()->with('user')->orderBy('first_message_at')->get()
            ->map(fn (ConversationParticipant $p) => [
                'user' => $p->user ? ['id' => $p->user->id, 'name' => $p->user->name, 'color' => $p->user->color] : null,
                'role' => $p->role?->value,
                'messages_count' => (int) $p->messages_count,
                'first_message_at' => $p->first_message_at?->toIso8601String(),
                'last_message_at' => $p->last_message_at?->toIso8601String(),
            ])->values();

        $window = $windows->evaluate($conversation, SenderType::User);
        $holder = $lock->holder($conversation);

        return response()->json([
            'conversation' => (new ConversationResource($conversation))->resolve($request),
            'messages' => MessageResource::collection($messages)->resolve($request),
            'notes' => NoteResource::collection($notes)->resolve($request),
            'customer' => $customer ? (new CustomerResource($customer))->resolve($request) : null,
            'participants' => $participants,
            'cases' => SupportCaseResource::collection(
                $conversation->cases()->with(['customer', 'assignedTo', 'order.items.variant'])->latest('id')->limit(5)->get()
            )->resolve($request),
            'window' => ['mode' => $window->mode, 'expires_at' => $window->expiresAt?->toIso8601String()],
            'lock' => [
                'holder' => $holder ? ['id' => $holder->id, 'name' => $holder->name] : null,
                'until' => $holder ? $conversation->locked_until?->toIso8601String() : null,
            ],
        ]);
    }

    public function messages(Request $request, Conversation $conversation): AnonymousResourceCollection
    {
        Gate::authorize('view', $conversation);

        $data = $request->validate(['before_id' => ['nullable', 'integer']]);
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
        } else {
            $body = $data['body'] ?? ('[template] '.$data['template']['name']);

            $message = $outbound->sendHuman($conversation, $request->user(), $body, $options);

            $response = (new MessageResource($message))->response()->setStatusCode(201);
        }

        // Usage is only recorded once the send actually dispatched (never
        // before — see the authorization check above, which already refused
        // the request for a reply the sender isn't allowed to use). The send
        // already succeeded at this point, so a usage-recording failure is
        // logged rather than turned into an error response for the caller.
        if ($quickReply !== null) {
            rescue(fn () => $usage->record($quickReply, $request->user(), $conversation), report: true);
        }

        return $response;
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

        return new MessageResource($message->load('user'));
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
        ]);

        return (new NoteResource($actions->addNote($conversation, $request->user(), $data['body'], $data['mentions'] ?? [])))
            ->response()->setStatusCode(201);
    }

    /**
     * Explicit "استلام" claim (spec §5.4, Task 15): any user who can reply may take
     * a forced soft lock, taking over from any current holder. Never blocks sending.
     */
    public function claim(Request $request, Conversation $conversation, SoftLock $lock): ConversationResource
    {
        Gate::authorize('reply', $conversation);

        $lock->claim($conversation, $request->user());

        return new ConversationResource($conversation->fresh(['customer', 'lockedBy', 'firstResponder', 'lastResponder', 'tags']));
    }

    /**
     * @mentions autocomplete data source (Task 15): active users who can access
     * this conversation's platform — id/name/color only, never phone numbers.
     */
    public function mentionable(Request $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);

        $users = User::query()->where('is_active', true)->with('userPlatforms')->orderBy('name')->get()
            ->filter(fn (User $u) => $u->canAccessPlatform($conversation->platform))
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'color' => $u->color])->values();

        return response()->json(['data' => $users]);
    }

    public function resolve(Request $request, Conversation $conversation, ConversationActions $actions): ConversationResource
    {
        Gate::authorize('reply', $conversation);

        return new ConversationResource($actions->resolve($conversation, $request->user()));
    }

    public function reopen(Request $request, Conversation $conversation, ConversationActions $actions): ConversationResource
    {
        Gate::authorize('reply', $conversation);

        return new ConversationResource($actions->reopen($conversation, $request->user()));
    }

    public function returnToBot(Request $request, Conversation $conversation, BotEngine $bot): ConversationResource
    {
        Gate::authorize('reply', $conversation);

        $bot->returnToBot($conversation, $request->user());

        return new ConversationResource($conversation);
    }

    public function reset(Request $request, Conversation $conversation, ConversationActions $actions): ConversationResource
    {
        Gate::authorize('reset', $conversation);

        $actions->reset($conversation, $request->user());

        return new ConversationResource($conversation->fresh(['customer', 'lockedBy', 'firstResponder', 'lastResponder', 'tags']));
    }

    public function read(Request $request, Conversation $conversation, ConversationActions $actions): ConversationResource
    {
        Gate::authorize('view', $conversation);

        return new ConversationResource($actions->markRead($conversation));
    }

    public function syncTags(Request $request, Conversation $conversation, ConversationActions $actions): ConversationResource
    {
        Gate::authorize('reply', $conversation);

        $data = $request->validate([
            'tag_ids' => ['present', 'array'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
        ]);

        return new ConversationResource($actions->syncTags($conversation, $data['tag_ids']));
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

        $conversation = $conversation->fresh();
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

            abort(response()->json(['message' => 'مفتاح الطلب ده مستخدم في طلب تاني — افتح نموذج الطلب من جديد.'], 409));
        }

        return (new OrderResource($order->loadMissing(['items', 'shipment.events', 'createdBy', 'customer'])))
            ->response()->setStatusCode($order->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * @return array{platform?: ?string, status?: ?string, filter?: ?string, q?: ?string, tag?: ?int}
     */
    protected function conversationFilters(Request $request): array
    {
        return $request->validate([
            'platform' => ['nullable', Rule::enum(Platform::class)],
            'status' => ['nullable', Rule::enum(ConversationStatus::class)],
            'filter' => ['nullable', Rule::in(ConversationQuery::FILTERS)],
            'q' => ['nullable', 'string', 'max:100'],
            'tag' => ['nullable', 'integer', 'exists:tags,id'],
        ]);
    }
}
