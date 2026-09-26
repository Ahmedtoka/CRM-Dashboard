<?php

namespace App\Shopify\Sync\Mappers;

use App\Commerce\Jobs\RefreshOrderStatus;
use App\Enums\ConversationStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Events\OrderUpdated;
use App\Inbox\OutboundService;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Shopify\Customers\CustomerOrderFlags;
use App\Shopify\Customers\PhoneNormalizer;
use App\Support\SafeBroadcast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class OrderMapper
{
    /**
     * Bulk import mode (null when off): customer ids whose flags wait for the chunk
     * commit. While on, historical orders are not announced in conversations and
     * no per-order OrderUpdated is broadcast (the import broadcasts progress).
     *
     * @var array<int, true>|null
     */
    private ?array $deferredCustomerIds = null;

    public function __construct(
        private readonly CustomerMapper $customers,
        private readonly CustomerOrderFlags $flags,
        private readonly OutboundService $outbound,
    ) {}

    public function beginBulk(): void
    {
        $this->deferredCustomerIds ??= [];
        $this->customers->muteBroadcasts(true);
    }

    public function endBulk(): void
    {
        $this->flushDeferredFlags();
        $this->deferredCustomerIds = null;
        $this->customers->muteBroadcasts(false);
    }

    /** Recomputes flags once per customer touched since the last flush; call after the chunk commits. */
    public function flushDeferredFlags(): void
    {
        if ($this->deferredCustomerIds === null || $this->deferredCustomerIds === []) {
            return;
        }

        $ids = array_keys($this->deferredCustomerIds);
        $this->deferredCustomerIds = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            Customer::whereKey($chunk)->get()->each(fn (Customer $customer) => $this->flags->recompute($customer, broadcast: false));
        }
    }

    public function upsert(array $order): MapResult
    {
        $o = Payload::isGraphql($order) ? $this->fromGraphql($order) : $order;
        $shopifyId = Payload::id($o['id'] ?? null) ?? throw new InvalidArgumentException('Shopify order payload has no id.');

        try {
            return $this->upsertLocked($o, $shopifyId);
        } catch (UniqueConstraintViolationException) {
            // Lost the create race on the new orders.shopify_order_id unique index:
            // the winning row now exists, so re-run the whole lookup/stale-check/
            // update under the lock against it.
            return $this->upsertLocked($o, $shopifyId);
        } catch (QueryException $e) {
            if (! $this->isDeadlock($e)) {
                throw $e;
            }

            // A genuine deadlock (two workers locking the same rows in opposite
            // order) is transient: one retry against a fresh transaction/lock is
            // enough, mirroring the unique-violation race above.
            return $this->upsertLocked($o, $shopifyId);
        }
    }

    /** Webhooks skip any copy not strictly newer; a bulk import only skips older copies (StaleGuard::isOlder). */
    private function isStale(mixed $stored, ?string $incoming): bool
    {
        return $this->deferredCustomerIds !== null
            ? StaleGuard::isOlder($stored, $incoming)
            : StaleGuard::isStale($stored, $incoming);
    }

    /**
     * MySQL/MariaDB SQLSTATE 40001 (serialization failure) / error 1213
     * (deadlock found when trying to get lock).
     */
    private function isDeadlock(QueryException $e): bool
    {
        if ($e->getCode() === '40001') {
            return true;
        }

        return (int) ($e->errorInfo[1] ?? 0) === 1213;
    }

    /**
     * Finds (with a row lock when a match exists) and applies the payload inside
     * one transaction, so a concurrent delivery of the same order can never read
     * stale data between the lookup and the stale-timestamp check.
     */
    private function upsertLocked(array $o, string $shopifyId): MapResult
    {
        return DB::transaction(function () use ($o, $shopifyId) {
            $local = $this->findLocal($o, $shopifyId, lock: true);

            if ($local !== null && $this->isStale($local->shopify_updated_at, $o['updated_at'] ?? null)) {
                return MapResult::Skipped;
            }

            if ($local !== null && $local->source === OrderSource::Chat) {
                $this->syncChatCustomer($local, $o);
                $this->updateChatOrder($local, $o, $shopifyId);
                $this->afterChange($local);

                return MapResult::Updated;
            }

            $customer = $this->resolveCustomer($o, $local);
            $created = $local === null;

            $model = $local ?? new Order(['source' => OrderSource::Store]);
            $this->fillStoreOrder($model, $o, $shopifyId, $customer);
            $model->save();

            if (array_key_exists('line_items', $o)) {
                $this->replaceItems($model, $o['line_items']);
            }

            if ($created && $this->deferredCustomerIds === null) {
                $this->announce($model);
            }

            $this->afterChange($model);

            return $created ? MapResult::Created : MapResult::Updated;
        });
    }

    public function applyFulfillment(array $fulfillment): MapResult
    {
        $f = Payload::isGraphql($fulfillment) ? $this->fulfillmentFromGraphql($fulfillment) : $fulfillment;
        $fulfillmentId = Payload::id($f['id'] ?? null) ?? throw new InvalidArgumentException('Shopify fulfillment payload has no id.');
        $order = $this->orderForChild($f['order_id'] ?? null);

        $existing = Fulfillment::where('shopify_fulfillment_id', $fulfillmentId)->first();

        if ($existing !== null && $this->isStale($existing->shopify_updated_at, $f['updated_at'] ?? null)) {
            return MapResult::Skipped;
        }

        Fulfillment::updateOrCreate(['shopify_fulfillment_id' => $fulfillmentId], [
            'order_id' => $order->id,
            'status' => Payload::lower($f['status'] ?? null),
            'tracking_company' => Payload::string($f['tracking_company'] ?? null),
            'tracking_number' => Payload::string($f['tracking_number'] ?? null) ?? Payload::string($f['tracking_numbers'][0] ?? null),
            'tracking_url' => Payload::string($f['tracking_url'] ?? null) ?? Payload::string($f['tracking_urls'][0] ?? null),
            'shipment_status' => Payload::lower($f['shipment_status'] ?? null),
            // GraphQL deliveredAt; a REST delivery only says shipment_status "delivered" (its updated_at is then the delivery time).
            'delivered_at' => Payload::time($f['delivered_at'] ?? null)
                ?? (Payload::lower($f['shipment_status'] ?? null) === 'delivered' ? ($existing?->delivered_at ?? Payload::time($f['updated_at'] ?? null)) : null),
            'shopify_created_at' => Payload::time($f['created_at'] ?? null),
            'shopify_updated_at' => Payload::time($f['updated_at'] ?? null),
        ]);

        $this->syncShipmentStatus($order);
        $this->afterChange($order);

        return $existing === null ? MapResult::Created : MapResult::Updated;
    }

    /**
     * Refunds are immutable in Shopify: a second delivery of the same refund is a no-op.
     */
    public function applyRefund(array $refund): MapResult
    {
        $r = Payload::isGraphql($refund) ? $this->refundFromGraphql($refund) : $refund;
        $refundId = Payload::id($r['id'] ?? null) ?? throw new InvalidArgumentException('Shopify refund payload has no id.');

        if (Refund::where('shopify_refund_id', $refundId)->exists()) {
            return MapResult::Skipped;
        }

        $order = $this->orderForChild($r['order_id'] ?? null);

        Refund::create([
            'order_id' => $order->id,
            'shopify_refund_id' => $refundId,
            'amount' => Payload::money($this->refundAmount($r)),
            'note' => Payload::string($r['note'] ?? null),
            'restock' => (bool) ($r['restock'] ?? false),
            'shopify_created_at' => Payload::time($r['created_at'] ?? null),
        ]);

        $this->afterChange($order);

        return MapResult::Created;
    }

    public function markCancelled(array $order): MapResult
    {
        $key = Payload::isGraphql($order) ? 'cancelledAt' : 'cancelled_at';

        if (blank($order[$key] ?? null)) {
            $order[$key] = now()->toIso8601String();
        }

        return $this->upsert($order);
    }

    /**
     * A fulfillment/refund must name its order; `where(col, null)` would otherwise match any
     * local order not yet pushed to Shopify. Unknown orders throw so the job retries later.
     */
    private function orderForChild(mixed $orderId): Order
    {
        $id = Payload::id($orderId) ?? throw new InvalidArgumentException('Shopify payload has no order_id.');

        return Order::where('shopify_order_id', $id)->firstOrFail();
    }

    /**
     * A `crm_order_id` note attribute can be set by anyone (it rides on a public
     * Shopify checkout/cart), so it only ever identifies a local order when that
     * order is itself CRM-created (source chat) and either has no Shopify order
     * yet or is already this exact one — never an unrelated chat order that
     * happens to share the number, and never a store order.
     */
    private function findLocal(array $o, string $shopifyId, bool $lock = false): ?Order
    {
        $crmOrderId = collect($o['note_attributes'] ?? [])
            ->first(fn ($attr) => ($attr['name'] ?? null) === 'crm_order_id')['value'] ?? null;

        if (is_numeric($crmOrderId) && (int) $crmOrderId > 0) {
            $candidate = $this->query(Order::query()->whereKey((int) $crmOrderId), $lock)->first();

            if ($candidate !== null
                && $candidate->source === OrderSource::Chat
                && ($candidate->shopify_order_id === null || $candidate->shopify_order_id === $shopifyId)) {
                return $candidate;
            }
        }

        if (($match = $this->query(Order::where('shopify_order_id', $shopifyId), $lock)->first()) !== null) {
            return $match;
        }

        $draftId = Payload::id($o['draft_order_id'] ?? $o['draft_order']['id'] ?? null);

        return $draftId !== null ? $this->query(Order::where('shopify_draft_order_id', $draftId), $lock)->first() : null;
    }

    private function query(Builder $query, bool $lock): Builder
    {
        return $lock ? $query->lockForUpdate() : $query;
    }

    /**
     * Chat orders were created in the CRM: only Shopify identity, statuses and
     * cancel bookkeeping fields change here, never attribution (created_by_id,
     * source, conversation_id) or the order's customer.
     *
     * Crucially, this never moves `status` to Confirmed/Cancelled or sets
     * `paid_at`: those transitions carry real side effects (shipment
     * auto-creation, customer stats, activity log) that only
     * `OrderService::markPaid`/`cancel` perform, and depend on the order's
     * *pre-webhook* status. `ShopifyWebhookProcessor` re-reads the order after
     * this call and drives that transition itself — doing it here would let
     * whichever order webhook happens to arrive first silently confirm/cancel
     * the order without ever calling through OrderService.
     */
    private function updateChatOrder(Order $order, array $o, string $shopifyId): void
    {
        $order->fill([
            'shopify_order_id' => $shopifyId,
            'shopify_order_name' => Payload::string($o['name'] ?? null) ?? $order->shopify_order_name,
            'financial_status' => Payload::lower($o['financial_status'] ?? null),
            'fulfillment_status' => Payload::lower($o['fulfillment_status'] ?? null),
            'cancelled_at' => Payload::time($o['cancelled_at'] ?? null),
            'cancel_reason' => Payload::string($o['cancel_reason'] ?? null),
            'shopify_updated_at' => Payload::time($o['updated_at'] ?? null),
        ]);

        if (array_key_exists('tags', $o)) {
            $order->tags = $this->tags($o['tags']);
        }

        if (blank($order->order_number)) {
            $order->order_number = $this->orderNumber($o);
        }

        $order->save();
    }

    /**
     * The Shopify customer of a CRM-created order is the order's own customer: adopt the
     * Shopify id when nobody holds it yet, so the upsert updates that row instead of
     * creating a duplicate.
     */
    private function syncChatCustomer(Order $order, array $o): void
    {
        $shopifyCustomerId = Payload::id($o['customer']['id'] ?? null);

        if ($shopifyCustomerId === null) {
            return;
        }

        $customer = $order->customer;

        if ($customer !== null && blank($customer->shopify_customer_id)
            && ! Customer::where('shopify_customer_id', $shopifyCustomerId)->exists()) {
            $customer->forceFill(['shopify_customer_id' => $shopifyCustomerId])->save();
        }

        $this->customers->upsert($o['customer']);
    }

    private function resolveCustomer(array $o, ?Order $local): Customer
    {
        $shopifyCustomerId = Payload::id($o['customer']['id'] ?? null);

        if ($shopifyCustomerId !== null) {
            $this->customers->upsert($o['customer']);

            if (($customer = Customer::where('shopify_customer_id', $shopifyCustomerId)->first()) !== null) {
                return $customer;
            }
        }

        $shipping = $o['shipping_address'] ?? [];
        $rawPhone = Payload::string($shipping['phone'] ?? null)
            ?? Payload::string($o['phone'] ?? null)
            ?? Payload::string($o['billing_address']['phone'] ?? null);
        $normalized = PhoneNormalizer::toE164($rawPhone);

        if ($normalized !== null && ($customer = Customer::where('normalized_phone', $normalized)->latest('id')->first()) !== null) {
            return $customer;
        }

        if ($local?->customer !== null) {
            return $local->customer;
        }

        $name = Payload::string($shipping['name'] ?? null)
            ?? (trim(((string) ($shipping['first_name'] ?? '')).' '.((string) ($shipping['last_name'] ?? ''))) ?: null)
            ?? Payload::string($o['billing_address']['name'] ?? null)
            ?? Payload::string($o['email'] ?? null);

        return Customer::create([
            'name' => $name,
            'phone' => $rawPhone,
            'normalized_phone' => $normalized,
            'email' => Payload::string($o['email'] ?? null),
            'city' => Payload::string($shipping['city'] ?? null) ?? Payload::string($shipping['province'] ?? null),
            'address' => $this->addressLine($shipping),
        ]);
    }

    private function fillStoreOrder(Order $order, array $o, string $shopifyId, Customer $customer): void
    {
        $shipping = $o['shipping_address'] ?? [];
        $financial = Payload::lower($o['financial_status'] ?? null);
        $gatewayNames = array_values(array_filter(array_map(fn ($g) => Payload::string($g), (array) ($o['payment_gateway_names'] ?? [$o['gateway'] ?? null]))));
        $gateways = strtolower(implode(' ', $gatewayNames));
        $isCod = str_contains($gateways, 'cash on delivery') || str_contains($gateways, 'cod') || $financial === 'pending';

        $order->fill([
            'customer_id' => $customer->id,
            'type' => $isCod ? OrderType::Cod : OrderType::PaymentLink,
            'payment_gateway' => $gatewayNames !== [] ? mb_substr(implode(', ', $gatewayNames), 0, 191) : null,
            'shopify_order_id' => $shopifyId,
            'shopify_order_name' => Payload::string($o['name'] ?? null),
            'order_number' => $this->orderNumber($o),
            'status' => blank($o['cancelled_at'] ?? null) ? OrderStatus::Confirmed : OrderStatus::Cancelled,
            'financial_status' => $financial,
            'fulfillment_status' => Payload::lower($o['fulfillment_status'] ?? null),
            'subtotal' => $this->moneyOrKeep($order, 'subtotal', $o, ['current_subtotal_price', 'subtotal_price']),
            'shipping_fee' => $this->moneyOrKeep($order, 'shipping_fee', $o, ['total_shipping_price_set']),
            'discount' => $this->moneyOrKeep($order, 'discount', $o, ['current_total_discounts', 'total_discounts']),
            'total' => $this->moneyOrKeep($order, 'total', $o, ['current_total_price', 'total_price']),
            'currency' => Payload::string($o['currency'] ?? null) ?? 'EGP',
            'shipping_name' => Payload::string($shipping['name'] ?? null),
            'shipping_phone' => Payload::string($shipping['phone'] ?? null) ?? Payload::string($o['phone'] ?? null),
            'billing_phone' => Payload::string($o['billing_address']['phone'] ?? null),
            'shipping_city' => Payload::string($shipping['city'] ?? null) ?? Payload::string($shipping['province'] ?? null),
            'shipping_province' => Payload::string($shipping['province'] ?? null),
            'shipping_province_code' => Payload::string($shipping['province_code'] ?? null),
            'shipping_address' => $this->addressLine($shipping),
            'shipping_title' => Payload::string($o['shipping_lines'][0]['title'] ?? null),
            'note' => Payload::string($o['note'] ?? null),
            'cancelled_at' => Payload::time($o['cancelled_at'] ?? null),
            'cancel_reason' => Payload::string($o['cancel_reason'] ?? null),
            'shopify_updated_at' => Payload::time($o['updated_at'] ?? null),
        ]);

        if (array_key_exists('tags', $o)) {
            $order->tags = $this->tags($o['tags']);
        }

        // When the customer placed it in the store (orders.created_at is the import time). Never blanked.
        $placedAt = Payload::time($o['created_at'] ?? null) ?? Payload::time($o['processed_at'] ?? null);

        if ($placedAt !== null) {
            $order->placed_at = $placedAt;
        }

        if ($order->paid_at === null && $financial === 'paid') {
            $order->paid_at = Payload::time($o['processed_at'] ?? null) ?? now();
        }
    }

    private function replaceItems(Order $order, mixed $lineItems): void
    {
        $lines = Payload::list($lineItems);
        $variantIds = ProductVariant::whereIn('shopify_id', array_filter(array_map(fn ($l) => Payload::id($l['variant_id'] ?? null), $lines)))
            ->pluck('id', 'shopify_id');

        $order->items()->delete();

        foreach ($lines as $line) {
            $allocations = collect($line['discount_allocations'] ?? []);
            $discount = $allocations->isNotEmpty()
                ? $allocations->sum(fn ($a) => Payload::amount($a['amount'] ?? $a['amount_set'] ?? null) ?? 0)
                : (Payload::amount($line['total_discount'] ?? null) ?? 0);

            $order->items()->create([
                'shopify_line_item_id' => Payload::id($line['id'] ?? null),
                'variant_id' => $variantIds->get(Payload::id($line['variant_id'] ?? null)),
                'title' => Payload::string($line['title'] ?? null) ?? Payload::string($line['name'] ?? null) ?? '',
                'variant_title' => Payload::string($line['variant_title'] ?? null),
                'sku' => Payload::string($line['sku'] ?? null),
                'qty' => (int) ($line['quantity'] ?? 1),
                'price' => Payload::money($line['price'] ?? null),
                'discount' => Payload::money($discount),
                'image_url' => Payload::string($line['image_url'] ?? null),
            ]);
        }
    }

    /**
     * Spec §4.2 point 8: a new store order posts a system line in the customer's most recently
     * active unresolved conversation. Best-effort: the order is already committed.
     */
    private function announce(Order $order): void
    {
        // Deferred past commit: the outbound send is real I/O (and, via the
        // system message, visible to the user), so it must never fire for a
        // row that a later error in the same transaction rolls back.
        DB::afterCommit(function () use ($order) {
            $conversation = Conversation::where('customer_id', $order->customer_id)
                ->where('status', '!=', ConversationStatus::Resolved->value)
                ->orderByDesc('last_message_at')
                ->orderByDesc('id')
                ->first();

            if ($conversation === null) {
                return;
            }

            $name = $order->shopify_order_name ?? ('#'.$order->order_number);
            $total = $this->formatAmount((float) $order->total);

            rescue(fn () => $this->outbound->sendSystem($conversation, "🛍️ طلب جديد من الموقع {$name} — {$total} ج.م"), null, report: true);
        });
    }

    private function afterChange(Order $order): void
    {
        if ($this->deferredCustomerIds !== null) {
            if ($order->customer_id !== null) {
                $this->deferredCustomerIds[(int) $order->customer_id] = true;
            }

            return;
        }

        if (($customer = Customer::find($order->customer_id)) !== null) {
            $this->flags->recompute($customer);
        }

        // Deferred past commit so listeners (and the realtime broadcast) never
        // observe a row from a transaction that ultimately rolled back.
        $orderId = $order->id;
        // Mismatch recompute (spec §6.1); bulk mode is covered by the post-import pass.
        DB::afterCommit(fn () => rescue(fn () => RefreshOrderStatus::dispatch($orderId), null, report: true));
        DB::afterCommit(fn () => SafeBroadcast::send(new OrderUpdated(Order::find($orderId) ?? $order)));
    }

    /**
     * The order's delivery progress is its latest live fulfillment's shipment
     * status (a cancelled fulfillment no longer ships anything); "fulfilled"
     * with no carrier update yet reads as the fulfillment status itself.
     */
    private function syncShipmentStatus(Order $order): void
    {
        $latest = $order->fulfillments()
            ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', ['cancelled', 'failure', 'error']))
            ->orderByDesc('shopify_created_at')
            ->orderByDesc('id')
            ->first();

        $order->forceFill([
            'shipment_status' => $latest === null ? null : ($latest->shipment_status ?? ($latest->status === 'success' ? 'fulfilled' : $latest->status)),
            'delivered_at' => $latest?->delivered_at,
        ])->saveQuietly();
    }

    /** REST sends "a, b", GraphQL a list; stored as one comma-separated line. */
    private function tags(mixed $tags): ?string
    {
        $list = is_array($tags) ? $tags : explode(',', (string) $tags);
        $list = array_values(array_filter(array_map(fn ($t) => trim((string) $t), $list), fn ($t) => $t !== ''));

        return $list === [] ? null : implode(', ', $list);
    }

    private function refundAmount(array $r): float
    {
        if (array_key_exists('total_refunded', $r)) {
            return Payload::amount($r['total_refunded']) ?? 0.0;
        }

        $transactions = collect($r['transactions'] ?? [])
            ->filter(fn ($t) => strtolower((string) ($t['kind'] ?? '')) === 'refund' && strtolower((string) ($t['status'] ?? '')) === 'success');

        if ($transactions->isNotEmpty()) {
            return (float) $transactions->sum(fn ($t) => Payload::amount($t['amount'] ?? null) ?? 0);
        }

        return (float) collect($r['refund_line_items'] ?? [])->sum(fn ($l) => Payload::amount($l['subtotal'] ?? null) ?? 0);
    }

    private function orderNumber(array $o): ?string
    {
        return Payload::string($o['order_number'] ?? null)
            ?? (($name = Payload::string($o['name'] ?? null)) !== null ? ltrim($name, '#') : null);
    }

    /**
     * A partial payload (e.g. a slimmer webhook delivery) that omits every key
     * for a money field must never reset it to 0.00 — only an explicitly
     * present key (REST or GraphQL Set) overwrites the stored amount.
     *
     * @param  list<string>  $keys
     */
    private function moneyOrKeep(Order $order, string $field, array $o, array $keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $o)) {
                return Payload::money($o[$key]);
            }
        }

        return $order->{$field} ?? '0.00';
    }

    private function addressLine(array $address): ?string
    {
        $line = implode('، ', array_filter([
            Payload::string($address['address1'] ?? null),
            Payload::string($address['address2'] ?? null),
        ]));

        return $line !== '' ? $line : null;
    }

    private function formatAmount(float $amount): string
    {
        return floor($amount) === $amount ? number_format($amount, 0, '.', '') : number_format($amount, 2, '.', '');
    }

    /**
     * GraphQL's OrderDisplayFulfillmentStatus enum vocabulary differs from the
     * REST `fulfillment_status` field it replaces here (spec §4.2): REST leaves
     * an unfulfilled order's field null and calls a part-fulfilled order
     * "partial", where GraphQL spells out UNFULFILLED/PARTIALLY_FULFILLED.
     * Everything else (e.g. RESTOCKED) is passed through lower-cased.
     */
    private function fulfillmentStatusFromGraphql(mixed $value): ?string
    {
        return match (Payload::lower($value)) {
            null, 'unfulfilled' => null,
            'partially_fulfilled' => 'partial',
            default => Payload::lower($value),
        };
    }

    private function fromGraphql(array $node): array
    {
        $address = fn (?array $a) => $a === null ? null : [
            'name' => $a['name'] ?? null,
            'first_name' => $a['firstName'] ?? null,
            'last_name' => $a['lastName'] ?? null,
            'phone' => $a['phone'] ?? null,
            'address1' => $a['address1'] ?? null,
            'address2' => $a['address2'] ?? null,
            'city' => $a['city'] ?? null,
            'province' => $a['province'] ?? null,
            'province_code' => $a['provinceCode'] ?? null,
            'zip' => $a['zip'] ?? null,
            'country_code' => $a['countryCodeV2'] ?? $a['countryCode'] ?? null,
        ];

        $o = [
            'id' => $node['id'],
            'name' => $node['name'] ?? null,
            'email' => $node['email'] ?? null,
            'phone' => $node['phone'] ?? null,
            'created_at' => $node['createdAt'] ?? null,
            'updated_at' => $node['updatedAt'] ?? null,
            'processed_at' => $node['processedAt'] ?? null,
            'cancelled_at' => $node['cancelledAt'] ?? null,
            'cancel_reason' => Payload::lower($node['cancelReason'] ?? null),
            'currency' => $node['currencyCode'] ?? null,
            'financial_status' => Payload::lower($node['displayFinancialStatus'] ?? null),
            'fulfillment_status' => $this->fulfillmentStatusFromGraphql($node['displayFulfillmentStatus'] ?? null),
            'note' => $node['note'] ?? null,
            'note_attributes' => array_map(
                fn ($attr) => ['name' => $attr['key'] ?? null, 'value' => $attr['value'] ?? null],
                $node['customAttributes'] ?? [],
            ),
            'payment_gateway_names' => $node['paymentGatewayNames'] ?? [],
            'shipping_address' => $address($node['shippingAddress'] ?? null) ?? [],
            'billing_address' => $address($node['billingAddress'] ?? null) ?? [],
            'shipping_lines' => isset($node['shippingLine']['title']) ? [['title' => $node['shippingLine']['title']]] : [],
            'draft_order_id' => null,
        ];

        // moneyOrKeep() (fillStoreOrder) treats a key's mere presence as "this
        // payload speaks to this amount"; only add it when the GraphQL node
        // actually carried the corresponding field, so a partial node (e.g. a
        // subscription that didn't request totals) never resets a stored
        // amount to 0.00.
        if (array_key_exists('currentSubtotalPriceSet', $node) || array_key_exists('subtotalPriceSet', $node)) {
            $o['current_subtotal_price'] = $node['currentSubtotalPriceSet'] ?? $node['subtotalPriceSet'] ?? null;
        }
        if (array_key_exists('currentTotalPriceSet', $node) || array_key_exists('totalPriceSet', $node)) {
            $o['current_total_price'] = $node['currentTotalPriceSet'] ?? $node['totalPriceSet'] ?? null;
        }
        if (array_key_exists('currentTotalDiscountsSet', $node) || array_key_exists('totalDiscountsSet', $node)) {
            $o['current_total_discounts'] = $node['currentTotalDiscountsSet'] ?? $node['totalDiscountsSet'] ?? null;
        }
        if (array_key_exists('totalShippingPriceSet', $node)) {
            $o['total_shipping_price_set'] = $node['totalShippingPriceSet'];
        }

        if (array_key_exists('tags', $node)) {
            $o['tags'] = $node['tags'];
        }

        if (isset($node['customer']['id'])) {
            $o['customer'] = $node['customer'];
        }

        if (array_key_exists('lineItems', $node)) {
            $o['line_items'] = array_map(fn (array $l) => [
                'id' => $l['id'] ?? null,
                'variant_id' => $l['variant']['id'] ?? null,
                'title' => $l['title'] ?? $l['name'] ?? null,
                'variant_title' => $l['variantTitle'] ?? null,
                'sku' => $l['sku'] ?? null,
                'quantity' => $l['currentQuantity'] ?? $l['quantity'] ?? 1,
                'price' => $l['originalUnitPriceSet'] ?? $l['originalUnitPrice'] ?? null,
                'total_discount' => $l['totalDiscountSet'] ?? null,
                'discount_allocations' => array_map(
                    fn ($d) => ['amount' => $d['allocatedAmountSet'] ?? null],
                    $l['discountAllocations'] ?? [],
                ),
                'image_url' => $l['image']['url'] ?? null,
            ], Payload::list($node['lineItems']));
        }

        return $o;
    }

    private function fulfillmentFromGraphql(array $node): array
    {
        $tracking = $node['trackingInfo'][0] ?? [];

        return [
            'id' => $node['id'],
            'order_id' => $node['order']['id'] ?? $node['orderId'] ?? null,
            'status' => $node['status'] ?? null,
            'tracking_company' => $tracking['company'] ?? null,
            'tracking_number' => $tracking['number'] ?? null,
            'tracking_url' => $tracking['url'] ?? null,
            'shipment_status' => $node['displayStatus'] ?? null,
            'delivered_at' => $node['deliveredAt'] ?? null,
            'created_at' => $node['createdAt'] ?? null,
            'updated_at' => $node['updatedAt'] ?? null,
        ];
    }

    private function refundFromGraphql(array $node): array
    {
        return [
            'id' => $node['id'],
            'order_id' => $node['order']['id'] ?? $node['orderId'] ?? null,
            'note' => $node['note'] ?? null,
            'created_at' => $node['createdAt'] ?? null,
            'total_refunded' => $node['totalRefundedSet'] ?? $node['totalRefunded'] ?? null,
            'restock' => false,
        ];
    }
}
