<?php

namespace App\Commerce;

use App\Analytics\ActivityLogger;
use App\Analytics\AttributionRecorder;
use App\Commerce\Contracts\CommerceProvider;
use App\Commerce\Data\CommerceResult;
use App\Commerce\Data\OrderPayload;
use App\Commerce\Data\OrderStatusUpdate;
use App\Commerce\Data\ShippingOption;
use App\Commerce\Jobs\SubmitOrderToProvider;
use App\Enums\ActorType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Events\OrderUpdated;
use App\Events\UserNotified;
use App\Inbox\OutboundService;
use App\Models\City;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\ShippingZone;
use App\Models\User;
use App\Shipping\ShipmentService;
use App\Shopify\Client\ShopifyException;
use App\Shopify\Connection\IntegrationRepository;
use App\Shopify\Connection\ShopifyIntegration;
use App\Shopify\Customers\PhoneNormalizer;
use App\Support\SafeBroadcast;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Orders created from a chat conversation (spec §5). Prices always come from
 * the DB (ProductVariant, ShippingRate/City), never from the request payload.
 * create() saves the order as `submitting` and queues SubmitOrderToProvider,
 * which calls submit() on the `commerce` queue.
 */
class OrderService
{
    public const DISCOUNT_FIXED = 'fixed';

    public const DISCOUNT_PERCENT = 'percent';

    /** Activity recorded when a follow-up step after a successful store submission fails. */
    public const POST_SUBMIT_FAILED = 'order.post_submit_failed';

    /** Lock TTL must satisfy: job timeout 60 < lock < retry_after 90 */
    private const SUBMIT_LOCK_SECONDS = 75;

    public function __construct(
        private readonly CommerceProvider $provider,
        private readonly ShipmentService $shipments,
        private readonly AttributionRecorder $attribution,
        private readonly ActivityLogger $logger,
        private readonly OutboundService $outbound,
        private readonly IntegrationRepository $integrations,
        private readonly ShippingQuote $quotes,
    ) {}

    /**
     * New shape: idempotency_key, shipping.{province_code, city, address1, address_id, rate_id},
     * discount {type, value, reason}. Legacy shape still accepted until the drawer and mobile
     * app migrate: no key (server UUID), shipping.{city_id, address}, discount as a number.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException a discount by a non supervisor/admin
     * @throws ValidationException
     * @throws DomainException 'idempotency_conflict' when the key belongs to another user/conversation
     */
    public function create(Conversation $conversation, User $user, array $data): Order
    {
        $key = trim((string) ($data['idempotency_key'] ?? ''));
        $key = $key !== '' ? $key : (string) Str::uuid();

        if ($existing = $this->findReplay($key, $conversation, $user)) {
            return $existing;
        }

        if (! $this->settings()['order_creation_enabled']) {
            throw ValidationException::withMessages(['order' => __('commerce.order.creation_disabled')]);
        }

        $items = $data['items'] ?? [];

        if (empty($items)) {
            throw ValidationException::withMessages(['items' => __('commerce.order.items_required')]);
        }

        $discount = $this->normalizeDiscount($data['discount'] ?? null);

        if ($discount['value'] > 0 && ! $user->isSupervisorOrAbove()) {
            throw new AuthorizationException(__('commerce.discount.supervisor_only'));
        }

        if ($discount['value'] > 0 && $discount['reason_required'] && $discount['reason'] === null) {
            throw ValidationException::withMessages(['discount.reason' => __('commerce.discount.reason_required')]);
        }

        $type = OrderType::from($data['type'] ?? OrderType::Cod->value);
        $shipping = $this->shippingInput($conversation, $data['shipping'] ?? []);
        $city = ! empty($shipping['city_id']) ? City::find($shipping['city_id']) : null;

        [$lineItems, $subtotalPiastres] = $this->priceItems($items);

        $option = $this->shippingOption($shipping, $subtotalPiastres, $city);
        $shippingFeePiastres = $this->toPiastres($option->price);
        // Never more than the goods subtotal (shipping is not discountable).
        $discountPiastres = min($subtotalPiastres, $discount['type'] === self::DISCOUNT_PERCENT
            ? (int) round($subtotalPiastres * $discount['value'] / 100)
            : $this->toPiastres($discount['value']));
        $totalPiastres = max(0, $subtotalPiastres + $shippingFeePiastres - $discountPiastres);

        $attributes = [
            'customer_id' => $conversation->customer_id,
            'conversation_id' => $conversation->id,
            'created_by_id' => $user->id,
            'platform' => $conversation->platform,
            'type' => $type,
            'source' => OrderSource::Chat,
            'status' => OrderStatus::Submitting,
            'idempotency_key' => $key,
            'subtotal' => $this->fromPiastres($subtotalPiastres),
            'shipping_fee' => $this->fromPiastres($shippingFeePiastres),
            'shipping_rate_id' => $option->rateId,
            // A zone rate keeps Shopify's own title; the default line stores the stable
            // Arabic one, so an English-mode staff member's order still reads «شحن» on
            // the customer's invoice.
            'shipping_title' => $option->rateId === null ? ShippingQuote::DEFAULT_TITLE : $option->title,
            'discount' => $this->fromPiastres($discountPiastres),
            'discount_type' => $discountPiastres > 0 ? $discount['type'] : null,
            'discount_value' => $discountPiastres > 0
                ? ($discount['type'] === self::DISCOUNT_PERCENT ? $this->money($discount['value']) : $this->fromPiastres($discountPiastres))
                : null,
            'discount_reason' => $discountPiastres > 0 ? $discount['reason'] : null,
            'total' => $this->fromPiastres($totalPiastres),
            'currency' => config('crm.currency', 'EGP'),
            'shipping_name' => $shipping['name'] ?? null,
            'shipping_phone' => $shipping['phone'] ?? null,
            'shipping_city' => $shipping['city'] ?? ($city?->name_ar ?? $city?->name_en),
            'shipping_province_code' => $shipping['province_code'] ?? null,
            'shipping_address' => $shipping['address1'] ?? ($shipping['address'] ?? null),
            'note' => $this->buildNote($user, $conversation, $data['note'] ?? null),
        ];

        try {
            $order = DB::transaction(function () use ($attributes, $lineItems) {
                $order = Order::create($attributes);

                foreach ($lineItems as $li) {
                    $order->items()->create([
                        'variant_id' => $li['variant_id'],
                        'title' => $li['title'],
                        'sku' => $li['sku'],
                        'qty' => $li['qty'],
                        'price' => $li['price'],
                    ]);
                }

                return $order;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Two requests with the same key raced past findReplay().
            return $this->findReplay($key, $conversation, $user) ?? throw $e;
        }

        $this->updateCustomerContact($order);

        SafeBroadcast::send(new OrderUpdated($order));

        $this->dispatchSubmission($order);

        $fresh = $order->fresh(['items', 'shipment']);
        $fresh->wasRecentlyCreated = true;

        return $fresh;
    }

    /**
     * Queue body (SubmitOrderToProvider): sends a `submitting` order to the store
     * using only the local order. User/auth errors fail it at once; throttled and
     * transport errors are rethrown for the queue to retry; any other exception
     * fails it so an order is never stranded in `submitting`.
     *
     * Two executions can never create two store orders: the whole submission
     * runs under the atomic `order-submit-{id}` lock (non-blocking).
     *
     * @return bool false when another execution holds the lock (nothing was done)
     *
     * @throws ShopifyException kinds 'throttled' and 'transport'
     */
    public function submit(int $orderId): bool
    {
        $lock = Cache::lock("order-submit-{$orderId}", self::SUBMIT_LOCK_SECONDS);

        if (! $lock->get()) {
            return false;
        }

        try {
            $this->submitLocked($orderId);
        } finally {
            $lock->release();
        }

        return true;
    }

    private function submitLocked(int $orderId): void
    {
        $order = Order::with(['items', 'customer', 'conversation', 'createdBy'])->find($orderId);

        if ($order === null || $order->status !== OrderStatus::Submitting) {
            return;
        }

        $variants = ProductVariant::with('product')
            ->whereIn('id', $order->items->pluck('variant_id')->filter())
            ->get()
            ->keyBy('id');

        $unlinked = $order->items->filter(fn (OrderItem $i) => empty($variants->get($i->variant_id)?->shopify_id));

        if ($unlinked->isNotEmpty()) {
            $this->failSubmission($order, $unlinked
                ->map(fn (OrderItem $i) => '«'.$this->itemLabel($i, $variants).'»: المنتج مش مربوط بـ Shopify')
                ->implode('؛ '));

            return;
        }

        // Already created by an earlier attempt that died before finalizing.
        if ($stored = $this->resultFromStoredIds($order)) {
            $this->applySubmissionSuccess($order, $stored);

            return;
        }

        try {
            $order->increment('submit_attempts');

            // A previous attempt may have created it and then failed (timeout/5xx
            // on the response): adopt that store order instead of creating a duplicate.
            $result = $order->submit_attempts > 1 ? $this->provider->findSubmittedOrder($order) : null;

            if ($result === null) {
                $address = $this->shippingAddressFor($order);
                $customerId = $order->customer ? $this->linkStoreCustomer($order->customer, $address) : null;
                $payload = $this->payloadFor($order, $variants, $address, $customerId);

                $result = $order->type === OrderType::PaymentLink
                    ? $this->provider->createPaymentLink($payload)
                    : $this->provider->createCodOrder($payload);
            }
        } catch (ShopifyException $e) {
            if (in_array($e->kind, ['throttled', 'transport'], true)) {
                throw $e;
            }

            $this->failSubmission($order, $this->describeShopifyError($order, $variants, $e));

            return;
        } catch (Throwable $e) {
            report($e);
            $this->failSubmission($order, 'تعذر إرسال الطلب: '.Str::limit($e->getMessage(), 200));

            return;
        }

        if (! $result->success) {
            $this->failSubmission($order, $result->error ?? 'Unknown error');

            return;
        }

        $this->applySubmissionSuccess($order, $result);
    }

    /**
     * SubmitOrderToProvider::failed(): retries exhausted on throttling/transport.
     */
    public function markSubmissionFailed(int $orderId, ?Throwable $exception): void
    {
        $order = Order::find($orderId);

        if ($order === null) {
            return;
        }

        $error = $exception instanceof ShopifyException && $exception->kind === 'throttled'
            ? 'Shopify مشغول ومقبلش الطلب بعد كذا محاولة — جرّب إعادة المحاولة'
            : 'تعذر الوصول لـ Shopify بعد كذا محاولة — جرّب إعادة المحاولة';

        if ($this->failSubmission($order, $error)) {
            $this->notifySupervisors('order.submit_failed', [
                'order_id' => $order->id,
                'conversation_id' => $order->conversation_id,
                'error' => $error,
            ]);
        }
    }

    /**
     * Re-submits a failed order (spec §5.3): its creator or a supervisor/admin.
     * The failed → submitting claim is atomic so a double click can't create
     * two Shopify orders. Attribution stays with the creator.
     *
     * @throws AuthorizationException
     * @throws ValidationException when the order is not in the failed state
     */
    public function retry(Order $order, User $user): Order
    {
        if (! $user->isSupervisorOrAbove() && (int) $order->created_by_id !== (int) $user->id) {
            throw new AuthorizationException(__('commerce.order.retry_forbidden'));
        }

        $claimed = Order::query()
            ->whereKey($order->id)
            ->where('status', OrderStatus::Failed->value)
            ->update(['status' => OrderStatus::Submitting->value, 'last_error' => null]);

        if ($claimed === 0) {
            $status = Order::whereKey($order->id)->value('status');

            throw ValidationException::withMessages([
                'order' => __('commerce.order.retry_not_failed', [
                    'status' => (string) ($status instanceof OrderStatus ? $status->value : $status),
                ]),
            ]);
        }

        $order = Order::findOrFail($order->id);

        $this->logger->log(ActorType::User, $user, ActivityLogger::ORDER_RETRIED, $order, $order->conversation);

        SafeBroadcast::send(new OrderUpdated($order));

        $this->dispatchSubmission($order);

        return $order->fresh(['items', 'shipment']);
    }

    /**
     * Only an order awaiting payment can become paid. Idempotent under concurrent
     * or duplicate delivery: the status check, the transition and the customer stats
     * increment happen in one transaction against a `lockForUpdate()`-locked row.
     * A paid signal for a cancelled/failed order is recorded and escalated to
     * supervisors instead of resurrecting it (money may need refunding).
     */
    public function markPaid(Order $order): Order
    {
        [$order, $outcome] = DB::transaction(function () use ($order) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->status === OrderStatus::Confirmed) {
                return [$locked, 'already_confirmed'];
            }

            if ($locked->status !== OrderStatus::AwaitingPayment) {
                return [$locked, 'ignored'];
            }

            $locked->forceFill([
                'status' => OrderStatus::Confirmed,
                'financial_status' => 'paid',
                'paid_at' => now(),
            ])->save();

            $this->applyCustomerStats($locked);

            return [$locked, 'paid'];
        });

        if ($outcome === 'already_confirmed') {
            return $order->fresh(['items', 'shipment']);
        }

        if ($outcome === 'ignored') {
            $this->recordIgnoredPayment($order);

            return $order->fresh(['items', 'shipment']);
        }

        if (! $order->shipment && $this->autoCreateShipment()) {
            $this->shipments->createFor($order);
        }

        $this->logger->log(
            ActorType::System,
            null,
            ActivityLogger::ORDER_PAID,
            $order,
            $order->conversation,
            ['total' => $order->total],
        );

        SafeBroadcast::send(new OrderUpdated($order));

        return $order->fresh(['items', 'shipment']);
    }

    /**
     * Idempotent even under concurrent/duplicate delivery (e.g. a retried
     * orders/cancelled webhook): the already-Cancelled check, the status
     * transition and the customer stats reversal all happen inside one
     * transaction against a `lockForUpdate()`-locked row, mirroring markPaid().
     *
     * A local cancel (supervisor+, not fulfilled) is then pushed to the commerce
     * provider ($syncProvider, restocking by default, never refunding); cancels
     * that came *from* Shopify pass false and a null user. A provider failure is
     * recorded but never undoes the local cancel.
     *
     * @throws AuthorizationException when a non supervisor/admin cancels
     * @throws DomainException 'already_fulfilled' for a fulfilled or partially fulfilled order
     */
    public function cancel(Order $order, ?User $user, bool $syncProvider = true, bool $restock = true): Order
    {
        if ($user !== null && ! $user->isSupervisorOrAbove()) {
            throw new AuthorizationException(__('commerce.order.cancel_forbidden'));
        }

        [$order, $alreadyCancelled] = DB::transaction(function () use ($order, $syncProvider) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->status === OrderStatus::Cancelled) {
                return [$locked, true];
            }

            if ($syncProvider && in_array($locked->fulfillment_status, ['fulfilled', 'partial'], true)) {
                throw new DomainException('already_fulfilled');
            }

            $wasConfirmed = $locked->status === OrderStatus::Confirmed;

            $locked->forceFill([
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => $locked->cancelled_at ?? now(),
            ])->save();

            // applyCustomerStats() only ever ran for a Confirmed order (the COD
            // submission or markPaid()); mirror that here so a cancelled order's
            // stats are only reversed if they were actually counted in.
            if ($wasConfirmed) {
                $this->reverseCustomerStats($locked);
            }

            return [$locked, false];
        });

        if ($alreadyCancelled) {
            return $order->fresh(['items', 'shipment']);
        }

        if ($order->shipment && ! in_array($order->shipment->status, [ShipmentStatus::Delivered, ShipmentStatus::Returned, ShipmentStatus::Cancelled], true)) {
            $this->shipments->applyEvent($order->shipment, ShipmentStatus::Cancelled, ShipmentService::EVENT_ORDER_CANCELLED);
        }

        $this->logger->log(
            $user ? ActorType::User : ActorType::System,
            $user,
            ActivityLogger::ORDER_CANCELLED,
            $order,
            $order->conversation,
        );

        if ($syncProvider && ($order->shopify_order_id !== null || $order->shopify_draft_order_id !== null)) {
            $this->cancelOnProvider($order, $user, $restock);
        }

        SafeBroadcast::send(new OrderUpdated($order));

        return $order->fresh(['items', 'shipment']);
    }

    public function applyUpdate(OrderStatusUpdate $update): ?Order
    {
        $order = $this->findByCrmOrderId($update) ?? $this->findByShopifyIds($update->shopifyOrderId, $update->draftOrderId);

        if ($order === null) {
            return null;
        }

        // A draft_orders/update conversion carries the new Shopify order id; without
        // backfilling it here, the orders/paid|updated|cancelled webhooks that follow
        // (which only carry that order id, not the draft id) could never find this row.
        if ($update->shopifyOrderId && $order->shopify_order_id === null) {
            $order->shopify_order_id = $update->shopifyOrderId;
        }

        if ($update->orderNumber && $order->order_number === null) {
            $order->order_number = $update->orderNumber;
        }

        if ($update->financialStatus) {
            $order->financial_status = $update->financialStatus;
        }

        if ($update->fulfillmentStatus) {
            $order->fulfillment_status = $update->fulfillmentStatus;
        }

        $order->save();

        if ($update->cancelled) {
            return $this->cancel($order, null, syncProvider: false);
        }

        if ($update->financialStatus === 'paid' && $order->status !== OrderStatus::Confirmed) {
            return $this->markPaid($order);
        }

        SafeBroadcast::send(new OrderUpdated($order));

        return $order->fresh(['items', 'shipment']);
    }

    /**
     * The `crm_order_id` note attribute set at creation identifies the order even
     * when Shopify's order webhook arrives before the draft conversion webhook.
     */
    private function findByCrmOrderId(OrderStatusUpdate $update): ?Order
    {
        if ($update->crmOrderId === null || ! ctype_digit($update->crmOrderId)) {
            return null;
        }

        $order = Order::find((int) $update->crmOrderId);

        if ($order === null) {
            return null;
        }

        // Guard against a foreign/stale attribute pointing at an unrelated order.
        if ($order->shopify_order_id !== null && $update->shopifyOrderId !== null && $order->shopify_order_id !== $update->shopifyOrderId) {
            return null;
        }

        return $order;
    }

    private function findByShopifyIds(?string $shopifyOrderId, ?string $draftOrderId): ?Order
    {
        if ($shopifyOrderId === null && $draftOrderId === null) {
            return null;
        }

        return Order::query()
            ->where(function ($q) use ($shopifyOrderId, $draftOrderId) {
                if ($shopifyOrderId !== null) {
                    $q->orWhere('shopify_order_id', $shopifyOrderId);
                }
                if ($draftOrderId !== null) {
                    $q->orWhere('shopify_draft_order_id', $draftOrderId);
                }
            })
            ->first();
    }

    /**
     * Same key from the same user in the same conversation → the existing order,
     * unchanged. The unique index is global, so a key reused elsewhere is a
     * conflict rather than a way to read someone else's order.
     */
    private function findReplay(string $key, Conversation $conversation, User $user): ?Order
    {
        $order = Order::query()->where('idempotency_key', $key)->first();

        if ($order === null) {
            return null;
        }

        if ((int) $order->conversation_id !== (int) $conversation->id || (int) $order->created_by_id !== (int) $user->id) {
            throw new DomainException('idempotency_conflict');
        }

        return $order->load(['items', 'shipment']);
    }

    private function dispatchSubmission(Order $order): void
    {
        try {
            SubmitOrderToProvider::dispatch($order->id);
        } catch (Throwable $e) {
            // On the sync queue a rethrown throttled/transport error lands here after
            // the job's failed() hook already failed the order; on a real queue the
            // push itself failed. Either way the order must not stay `submitting`.
            if (! $e instanceof ShopifyException) {
                report($e);
            }

            $this->failSubmission($order, 'تعذر إرسال الطلب لـ Shopify — جرّب إعادة المحاولة');
        }
    }

    /**
     * submitting → failed (atomic), with the reason in the chat.
     *
     * @return bool whether this call moved the order to failed
     */
    private function failSubmission(Order $order, string $error): bool
    {
        $claimed = Order::query()
            ->whereKey($order->id)
            ->where('status', OrderStatus::Submitting->value)
            ->update(['status' => OrderStatus::Failed->value, 'last_error' => $error]);

        if ($claimed === 0) {
            return false;
        }

        $order->refresh();

        if ($order->conversation) {
            $this->announceFailure($order->conversation, $error);
        }

        SafeBroadcast::send(new OrderUpdated($order));

        return true;
    }

    private function applySubmissionSuccess(Order $order, CommerceResult $result): void
    {
        $cod = $order->type !== OrderType::PaymentLink;

        $ids = $cod
            ? ['shopify_order_id' => $result->orderId, 'order_number' => $result->orderNumber, 'shopify_order_name' => $result->orderNumber]
            : ['shopify_draft_order_id' => $result->draftOrderId, 'invoice_url' => $result->invoiceUrl];

        $state = $cod
            ? ['status' => OrderStatus::Confirmed->value, 'financial_status' => 'pending']
            : ['status' => OrderStatus::AwaitingPayment->value];

        // The customer pays Shopify's draft total: flag it when it isn't ours.
        $mismatch = ! $cod && $result->total !== null && abs((float) $result->total - (float) $order->total) > 0.01;
        $state['last_error'] = $mismatch
            ? 'إجمالي Shopify '.$this->money($result->total).' مختلف عن إجمالي الطلب '.$this->money($order->total)
            : null;

        if ($mismatch) {
            $state['mismatch'] = true;
            $state['mismatch_reason'] = OrderStatusResolver::SHOPIFY_TOTAL_DIFFERS;
        }

        $claimed = Order::query()
            ->whereKey($order->id)
            ->where('status', OrderStatus::Submitting->value)
            ->update($ids + $state);

        if ($claimed === 0) {
            // Cancelled locally while the store was creating it: keep the ids so
            // webhooks still match, and undo it on the store.
            Order::query()->whereKey($order->id)->update($ids);
            $order->refresh();

            if ($order->status === OrderStatus::Cancelled) {
                $this->cancelOnProvider($order, null);
            }

            return;
        }

        $order->refresh();

        // The store order exists and the row says so: each follow-up step is
        // isolated so one failure (carrier, chat, broadcast) can't skip the rest.
        if ($cod) {
            $this->postSubmitStep($order, 'customer_stats', fn () => $this->applyCustomerStats($order));
            $this->postSubmitStep($order, 'shipment', function () use ($order) {
                if ($this->autoCreateShipment() && ! $order->shipment()->exists()) {
                    $this->shipments->createFor($order);
                }
            });
        }

        $this->postSubmitStep($order, 'attribution', fn () => $this->attribution->recordOrder($order));

        $this->postSubmitStep($order, 'chat_line', function () use ($order, $cod, $result) {
            if ($order->conversation === null) {
                return;
            }

            $cod
                ? $this->announce($order, $order->conversation, $order->createdBy)
                : $this->outbound->sendSystem(
                    $order->conversation,
                    '🔗 رابط دفع لطلب '.($result->orderNumber ?? '#'.$order->id).' — '.($result->total !== null ? $this->money($result->total) : $order->total).' ج.م',
                );
        });

        $this->postSubmitStep($order, 'broadcast', fn () => SafeBroadcast::send(new OrderUpdated($order)));
    }

    private function postSubmitStep(Order $order, string $step, callable $run): void
    {
        try {
            $run();
        } catch (Throwable $e) {
            report($e);

            try {
                $this->logger->log(ActorType::System, null, self::POST_SUBMIT_FAILED, $order, $order->conversation, [
                    'step' => $step,
                    'error' => Str::limit($e->getMessage(), 250),
                ]);
            } catch (Throwable $logFailure) {
                report($logFailure);
            }
        }
    }

    /**
     * The row already carries the store ids (a prior attempt created it but
     * crashed before the status update).
     */
    private function resultFromStoredIds(Order $order): ?CommerceResult
    {
        if ($order->type === OrderType::PaymentLink) {
            return $order->shopify_draft_order_id !== null
                ? new CommerceResult(success: true, draftOrderId: $order->shopify_draft_order_id, invoiceUrl: $order->invoice_url)
                : null;
        }

        return $order->shopify_order_id !== null
            ? new CommerceResult(success: true, orderId: $order->shopify_order_id, orderNumber: $order->order_number ?? $order->shopify_order_name)
            : null;
    }

    /**
     * @param  Collection<int, ProductVariant>  $variants  keyed by id
     * @param  array<string, string>  $address
     */
    private function payloadFor(Order $order, Collection $variants, array $address, ?string $customerId): OrderPayload
    {
        $creator = $order->createdBy;
        $platform = $order->conversation?->platform ?? $order->platform;

        return new OrderPayload(
            order: $order,
            lineItems: $order->items->sortBy('id')->map(fn (OrderItem $i) => [
                'variant_shopify_id' => (string) $variants->get($i->variant_id)->shopify_id,
                'title' => $i->title,
                'qty' => (int) $i->qty,
                'price' => $this->money($i->price),
            ])->values()->all(),
            tags: array_values(array_filter([
                'social-crm',
                $platform ? 'platform:'.$platform->value : null,
                $creator ? $this->moderatorTag($creator) : null,
                OrderPayload::tagFor($order->id),
            ])),
            note: $this->providerNote($order),
            noteAttributes: array_filter([
                'crm_order_id' => $order->id,
                'crm_conversation_id' => $order->conversation_id,
                'crm_user_id' => $order->created_by_id,
            ], fn ($v) => $v !== null),
            customerId: $customerId,
            shippingAddress: $address,
            shippingLine: ['title' => $order->shipping_title ?: ShippingQuote::DEFAULT_TITLE, 'price' => $this->money($order->shipping_fee)],
            discount: $this->toPiastres($order->discount) > 0 ? [
                'type' => $order->discount_type ?: self::DISCOUNT_FIXED,
                'value' => $this->money($order->discount_value ?? $order->discount),
                'amount' => $this->money($order->discount),
                'reason' => $order->discount_reason,
            ] : null,
        );
    }

    /**
     * @return array<string, string>
     */
    private function shippingAddressFor(Order $order): array
    {
        $names = preg_split('/\s+/u', trim((string) ($order->shipping_name ?: $order->customer?->name)), 2) ?: [];

        return array_filter([
            'firstName' => $names[0] ?? null,
            'lastName' => $names[1] ?? null,
            'phone' => PhoneNormalizer::toE164($order->shipping_phone) ?? $order->shipping_phone,
            'address1' => $order->shipping_address,
            'city' => $order->shipping_city,
            'provinceCode' => $order->shipping_province_code,
            'countryCode' => 'EG',
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Links the store customer id locally unless another local customer already owns it.
     *
     * @param  array<string, string>  $address
     */
    private function linkStoreCustomer(Customer $customer, array $address): string
    {
        $id = $this->provider->ensureCustomer($customer, $address);

        if ($id !== ''
            && (string) $customer->shopify_customer_id !== $id
            && Customer::query()->where('shopify_customer_id', $id)->whereKeyNot($customer->id)->doesntExist()) {
            $customer->forceFill(['shopify_customer_id' => $id])->save();
        }

        return $id;
    }

    /**
     * Arabic `last_error` (spec §5.3): a user error on `lineItems.N` names that
     * item («product — variant»); anything else is Shopify's own message.
     *
     * @param  Collection<int, ProductVariant>  $variants
     */
    private function describeShopifyError(Order $order, Collection $variants, ShopifyException $e): string
    {
        if (in_array($e->kind, ['auth', 'not_connected'], true)) {
            return 'Shopify غير متصل';
        }

        $items = $order->items->sortBy('id')->values();
        $errors = $e->userErrors !== [] ? $e->userErrors : [['field' => null, 'message' => $e->getMessage()]];

        return collect($errors)->map(function ($error) use ($items, $variants) {
            $message = (string) ($error['message'] ?? '');
            $field = array_map('strval', (array) ($error['field'] ?? []));
            $position = array_search('lineItems', $field, true);
            $index = $position !== false && isset($field[$position + 1]) && ctype_digit($field[$position + 1]) ? (int) $field[$position + 1] : null;
            $item = $index !== null ? $items->get($index) : null;

            return $item
                ? '«'.$this->itemLabel($item, $variants).'»: '.$this->arabicReason($message)
                : 'Shopify: '.$message;
        })->implode('؛ ');
    }

    /**
     * @param  Collection<int, ProductVariant>  $variants
     */
    private function itemLabel(OrderItem $item, Collection $variants): string
    {
        $variant = $variants->get($item->variant_id);
        $title = $variant?->product?->title ?? $item->title;
        $variantTitle = $variant?->title;

        return in_array($variantTitle, [null, '', 'Default', 'Default Title'], true) ? $title : "{$title} — {$variantTitle}";
    }

    private function arabicReason(string $message): string
    {
        $m = Str::lower($message);

        return match (true) {
            Str::contains($m, ['inventory', 'stock', 'quantity', 'available']) => 'الكمية غير متوفرة',
            Str::contains($m, ['not found', 'does not exist', 'invalid variant']) => 'المنتج مش موجود على Shopify',
            Str::contains($m, 'price') => 'السعر اتغير على Shopify',
            default => $message,
        };
    }

    /**
     * The note sent to Shopify, without the "[Shopify error]" lines older orders carry.
     */
    private function providerNote(Order $order): string
    {
        return trim(preg_replace('/\n\[Shopify error\][^\n]*/', '', (string) $order->note) ?? '');
    }

    private function cancelOnProvider(Order $order, ?User $user, bool $restock = true): void
    {
        try {
            $result = $this->provider->cancelOrder($order, $restock);
        } catch (Throwable $e) {
            report($e);
            $result = new CommerceResult(success: false, error: $e->getMessage());
        }

        if ($result->success) {
            return;
        }

        $this->logger->log(
            $user ? ActorType::User : ActorType::System,
            $user,
            ActivityLogger::ORDER_CANCEL_SYNC_FAILED,
            $order,
            $order->conversation,
            ['error' => $result->error ?? 'Unknown error'],
        );
    }

    private function recordIgnoredPayment(Order $order): void
    {
        $this->logger->log(
            ActorType::System,
            null,
            ActivityLogger::ORDER_PAID_IGNORED,
            $order,
            $order->conversation,
            ['status' => $order->status?->value, 'total' => $order->total],
        );

        $this->notifySupervisors('order.paid_ignored', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status?->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function notifySupervisors(string $type, array $data): void
    {
        User::query()
            ->where('is_active', true)
            ->whereIn('role', [UserRole::Supervisor->value, UserRole::Admin->value])
            ->get()
            ->each(fn (User $u) => SafeBroadcast::send(new UserNotified($u->id, $type, $data)));
    }

    /**
     * @return array{type: string, value: float, reason: ?string, reason_required: bool}
     */
    private function normalizeDiscount(mixed $discount): array
    {
        if ($discount === null || $discount === '') {
            return ['type' => self::DISCOUNT_FIXED, 'value' => 0.0, 'reason' => null, 'reason_required' => false];
        }

        // Legacy shape: a plain amount, reason optional.
        if (is_numeric($discount)) {
            return ['type' => self::DISCOUNT_FIXED, 'value' => max(0.0, (float) $discount), 'reason' => null, 'reason_required' => false];
        }

        if (! is_array($discount)) {
            throw ValidationException::withMessages(['discount' => __('commerce.discount.invalid_value')]);
        }

        $type = $discount['type'] ?? self::DISCOUNT_FIXED;

        if (! in_array($type, [self::DISCOUNT_FIXED, self::DISCOUNT_PERCENT], true)) {
            throw ValidationException::withMessages(['discount.type' => __('commerce.discount.invalid_type')]);
        }

        $value = (float) ($discount['value'] ?? 0);

        if ($value < 0 || ($type === self::DISCOUNT_PERCENT && $value > 100)) {
            throw ValidationException::withMessages(['discount.value' => __('commerce.discount.invalid_value')]);
        }

        $reason = trim((string) ($discount['reason'] ?? ''));

        return ['type' => $type, 'value' => $value, 'reason' => $reason !== '' ? $reason : null, 'reason_required' => true];
    }

    /**
     * Fills blanks in the shipping input from a saved customer address.
     *
     * @param  array<string, mixed>  $shipping
     * @return array<string, mixed>
     */
    private function shippingInput(Conversation $conversation, array $shipping): array
    {
        if (empty($shipping['address_id'])) {
            return $shipping;
        }

        $address = CustomerAddress::query()->where('customer_id', $conversation->customer_id)->find($shipping['address_id']);

        if ($address === null) {
            throw ValidationException::withMessages(['shipping.address_id' => __('commerce.shipping.address_not_customers')]);
        }

        $defaults = [
            'name' => $address->name,
            'phone' => $address->phone,
            'address1' => trim($address->address1.' '.$address->address2),
            'city' => $address->city,
            'province_code' => $address->province_code,
        ];

        foreach ($defaults as $key => $value) {
            if (empty($shipping[$key]) && $value !== null && $value !== '') {
                $shipping[$key] = $value;
            }
        }

        return $shipping;
    }

    /**
     * Legacy `city_id` fee only while no shipping zones are stored; otherwise the
     * zone rate (chosen, or the first one) or the default fee.
     *
     * @param  array<string, mixed>  $shipping
     */
    private function shippingOption(array $shipping, int $subtotalPiastres, ?City $city): ShippingOption
    {
        if ($city !== null && ! ShippingZone::query()->exists()) {
            return new ShippingOption(null, ShippingQuote::DEFAULT_TITLE, $this->money($city->shipping_fee ?? 0));
        }

        $options = $this->quotes->quote($shipping['province_code'] ?? null, $this->fromPiastres($subtotalPiastres));

        if (empty($shipping['rate_id'])) {
            return $options[0];
        }

        foreach ($options as $option) {
            if ($option->rateId === (int) $shipping['rate_id']) {
                return $option;
            }
        }

        throw ValidationException::withMessages(['shipping.rate_id' => __('commerce.shipping.rate_unavailable')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        return ($this->integrations->current() ?? new ShopifyIntegration)->settingsWithDefaults();
    }

    private function autoCreateShipment(): bool
    {
        return (bool) config('crm.auto_create_shipment', true) && (bool) $this->settings()['auto_create_shipment'];
    }

    /**
     * @param  array<int, array{variant_id: int, qty: int}>  $items
     * @return array{0: array<int, array{variant_id: int, title: string, sku: ?string, qty: int, price: string}>, 1: int}
     */
    private function priceItems(array $items): array
    {
        $variantIds = array_column($items, 'variant_id');
        $variants = ProductVariant::with('product')->whereIn('id', $variantIds)->get()->keyBy('id');

        $lineItems = [];
        $subtotalPiastres = 0;

        foreach ($items as $item) {
            $variant = $variants->get($item['variant_id'] ?? null);

            if ($variant === null) {
                throw ValidationException::withMessages([
                    'items' => __('commerce.order.variant_not_found', ['id' => (string) ($item['variant_id'] ?? '')]),
                ]);
            }

            $qty = max(1, (int) ($item['qty'] ?? 1));
            $pricePiastres = $this->toPiastres($variant->price);
            $subtotalPiastres += $pricePiastres * $qty;

            $lineItems[] = [
                'variant_id' => $variant->id,
                'title' => $variant->product?->title ?? $variant->title,
                'sku' => $variant->sku,
                'qty' => $qty,
                'price' => $this->fromPiastres($pricePiastres),
            ];
        }

        return [$lineItems, $subtotalPiastres];
    }

    private function buildNote(User $user, Conversation $conversation, ?string $userNote): string
    {
        $note = "Created by {$user->name} from {$conversation->platform->label()} conversation #{$conversation->id} — Social CRM";

        if (! empty($userNote)) {
            $note .= "\n".$userNote;
        }

        return $note;
    }

    private function announce(Order $order, Conversation $conversation, ?User $user): void
    {
        $number = $order->order_number ?? ('#'.$order->id);
        $by = $user?->name ?? 'Social CRM';

        $this->outbound->sendSystem(
            $conversation,
            "🛒 أوردر {$number} اتعمل بواسطة {$by} — {$order->total} جنيه",
        );
    }

    private function announceFailure(Conversation $conversation, ?string $error): void
    {
        $this->outbound->sendSystem(
            $conversation,
            'تعذر إنشاء الأوردر على Shopify — '.($error ?? 'Unknown error'),
        );
    }

    /**
     * `Str::slug()` phonetically transliterates Arabic rather than returning ''
     * (e.g. "منى علي" -> "mn-aaly"), which is not a stable/readable username. So a
     * name with no Latin characters at all skips slugging entirely and falls back
     * to the email's local part, then a stable id-based tag.
     */
    private function moderatorTag(User $user): string
    {
        $slug = preg_match('/[A-Za-z]/', $user->name) === 1 ? Str::slug($user->name) : '';

        if ($slug === '' && ! empty($user->email)) {
            $slug = Str::before($user->email, '@');
        }

        if ($slug === '') {
            $slug = 'user-'.$user->id;
        }

        return 'mod:'.$slug;
    }

    private function applyCustomerStats(Order $order): void
    {
        $customer = $order->customer;

        if ($customer === null) {
            return;
        }

        $customer->orders_count = (int) $customer->orders_count + 1;
        $customer->total_spent = round((float) $customer->total_spent + (float) $order->total, 2);
        $customer->save();
    }

    /**
     * Mirrors applyCustomerStats() for a Confirmed order that is being cancelled,
     * clamped at 0 so it can never go negative (e.g. if stats were adjusted
     * elsewhere in the meantime).
     */
    private function reverseCustomerStats(Order $order): void
    {
        $customer = $order->customer;

        if ($customer === null) {
            return;
        }

        $customer->orders_count = max(0, (int) $customer->orders_count - 1);
        $customer->total_spent = max(0.0, round((float) $customer->total_spent - (float) $order->total, 2));
        $customer->save();
    }

    private function updateCustomerContact(Order $order): void
    {
        $customer = $order->customer;

        if ($customer === null) {
            return;
        }

        $fill = [
            'name' => $order->shipping_name,
            'phone' => $order->shipping_phone,
            'address' => $order->shipping_address,
            'city' => $order->shipping_city,
        ];

        $dirty = false;

        foreach ($fill as $column => $value) {
            if (empty($customer->{$column}) && ! empty($value)) {
                $customer->{$column} = $value;
                $dirty = true;
            }
        }

        if ($dirty) {
            $customer->save();
        }
    }

    private function money(string|float|int|null $amount): string
    {
        return $this->fromPiastres($this->toPiastres($amount));
    }

    private function toPiastres(string|float|int|null $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function fromPiastres(int $piastres): string
    {
        return number_format($piastres / 100, 2, '.', '');
    }
}
