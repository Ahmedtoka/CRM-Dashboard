<?php

namespace App\Http\Controllers\Concerns;

use App\Bot\BotEngine;
use App\Commerce\OrderService;
use App\Enums\ConversationPriority;
use App\Enums\ConversationStatus;
use App\Enums\OrderType;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\MessageResource;
use App\Http\Resources\NoteResource;
use App\Http\Resources\OrderResource;
use App\Events\ConversationUpdated;
use App\Http\Support\ModeratorScope;
use App\Inbox\ConversationActions;
use App\Inbox\ConversationPriorityClassifier;
use App\Inbox\ConversationQuery;
use App\Inbox\OutboundService;
use App\Inbox\SoftLock;
use App\Inbox\WindowPolicy;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Support\SafeBroadcast;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
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

        $conversation->load(['customer', 'lockedBy', 'firstResponder', 'tags']);

        $messages = $conversation->messages()->with('user')->orderByDesc('id')->limit(50)->get()->reverse()->values();
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

        $rows = $conversation->messages()->with('user')
            ->when($data['before_id'] ?? null, fn ($q, $before) => $q->where('id', '<', $before))
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        return MessageResource::collection($rows->take($limit)->reverse()->values())
            ->additional(['has_more' => $rows->count() > $limit]);
    }

    public function sendMessage(Request $request, Conversation $conversation, OutboundService $outbound): JsonResponse
    {
        Gate::authorize('reply', $conversation);

        $data = $request->validate([
            'body' => ['required_without:template', 'nullable', 'string', 'max:4000'],
            'template' => ['nullable', 'array'],
            'template.name' => ['required_with:template', 'string', 'max:255'],
            'template.language' => ['nullable', 'string', 'max:10'],
            'template.params' => ['nullable', 'array'],
        ]);

        $options = [];

        if (! empty($data['template'])) {
            $options['template'] = [
                'name' => $data['template']['name'],
                'language' => $data['template']['language'] ?? 'ar',
                'params' => array_values($data['template']['params'] ?? []),
            ];
        }

        $body = $data['body'] ?? ('[template] '.$data['template']['name']);

        $message = $outbound->sendHuman($conversation, $request->user(), $body, $options);

        return (new MessageResource($message))->response()->setStatusCode(201);
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

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        return (new NoteResource($actions->addNote($conversation, $request->user(), $data['body'])))
            ->response()->setStatusCode(201);
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

        // `idempotency_key` stays optional (server-generated) and `shipping.city_id`
        // stays accepted until the web drawer and mobile app send the new shape.
        $data = $request->validate([
            'idempotency_key' => ['nullable', 'uuid'],
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
     * @return array{platform?: ?string, status?: ?string, filter?: ?string, q?: ?string}
     */
    protected function conversationFilters(Request $request): array
    {
        return $request->validate([
            'platform' => ['nullable', Rule::enum(Platform::class)],
            'status' => ['nullable', Rule::enum(ConversationStatus::class)],
            'filter' => ['nullable', Rule::in(ConversationQuery::FILTERS)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
    }
}
