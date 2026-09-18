<?php

namespace App\Commerce;

use App\Commerce\Contracts\CommerceProvider;
use App\Commerce\Data\CommerceResult;
use App\Commerce\Data\OrderPayload;
use App\Commerce\Data\OrderStatusUpdate;
use App\Enums\OrderType;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Shopify\Client\ShopifyClient;
use App\Shopify\Client\ShopifyException;
use App\Shopify\Connection\IntegrationRepository;
use App\Shopify\Customers\PhoneNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Throwable;

/**
 * Live Shopify driver (crm.drivers.commerce = 'live'). Every call goes through
 * ShopifyClient (spec §2, §5.2): user errors, auth, throttling and transport
 * failures surface as ShopifyException for the submission job to classify.
 */
class ShopifyCommerceProvider implements CommerceProvider
{
    private const DISCOUNT_CODE = 'SOCIAL-CRM';

    public function __construct(
        private readonly IntegrationRepository $integrations,
        private readonly ShopifyClient $client,
    ) {}

    public function ensureCustomer(Customer $customer, array $shippingAddress): string
    {
        if (! empty($customer->shopify_customer_id)) {
            return (string) $customer->shopify_customer_id;
        }

        $phone = PhoneNormalizer::toE164($shippingAddress['phone'] ?? null) ?? PhoneNormalizer::toE164($customer->phone);

        if ($phone !== null) {
            $found = $this->client->query(
                'query customerByPhone($query: String!) { customers(first: 1, query: $query) { nodes { id } } }',
                ['query' => "phone:{$phone}"],
            );

            if ($id = $this->numericId($found['customers']['nodes'][0]['id'] ?? null)) {
                return $id;
            }
        }

        $fullName = $customer->name ?: trim(($shippingAddress['firstName'] ?? '').' '.($shippingAddress['lastName'] ?? ''));
        $names = preg_split('/\s+/u', trim((string) $fullName), 2) ?: [];

        $input = array_filter([
            'firstName' => ($names[0] ?? '') !== '' ? $names[0] : null,
            'lastName' => $names[1] ?? null,
            'phone' => $phone,
            'email' => $customer->email ?: null,
            'addresses' => $shippingAddress !== [] ? [$this->mailingAddress($shippingAddress)] : null,
        ], fn ($v) => $v !== null);

        $data = $this->client->mutate(
            'mutation customerCreate($input: CustomerInput!) { customerCreate(input: $input) { customer { id } userErrors { field message } } }',
            ['input' => $input],
            'customerCreate',
        );

        return $this->numericId($data['customerCreate']['customer']['id'] ?? null)
            ?? throw new ShopifyException('transport', 'Shopify returned no customer');
    }

    public function createCodOrder(OrderPayload $payload): CommerceResult
    {
        $currency = $payload->order->currency ?: 'EGP';

        $order = array_filter([
            'lineItems' => array_map(fn (array $li) => [
                'variantId' => "gid://shopify/ProductVariant/{$li['variant_shopify_id']}",
                'quantity' => $li['qty'],
                'priceSet' => ['shopMoney' => ['amount' => $li['price'], 'currencyCode' => $currency]],
            ], $payload->lineItems),
            'customer' => $payload->customerId ? ['toAssociate' => ['id' => "gid://shopify/Customer/{$payload->customerId}"]] : null,
            'phone' => $payload->shippingAddress['phone'] ?? null,
            'shippingAddress' => $payload->shippingAddress !== [] ? $this->mailingAddress($payload->shippingAddress) : null,
            'shippingLines' => [[
                'title' => $payload->shippingLine['title'] ?? ShippingQuote::DEFAULT_TITLE,
                'priceSet' => ['shopMoney' => ['amount' => $payload->shippingLine['price'] ?? '0.00', 'currencyCode' => $currency]],
            ]],
            'discountCode' => $this->orderDiscountCode($payload, $currency),
            'financialStatus' => 'PENDING',
            'tags' => $payload->tags,
            'note' => $payload->note,
            'customAttributes' => $this->customAttributes($payload),
        ], fn ($v) => $v !== null);

        $data = $this->client->mutate(
            'mutation orderCreate($order: OrderCreateOrderInput!, $options: OrderCreateOptionsInput) { orderCreate(order: $order, options: $options) { order { id name } userErrors { field message } } }',
            [
                'order' => $order,
                'options' => ['inventoryBehaviour' => 'DECREMENT_OBEYING_POLICY', 'sendReceipt' => false, 'sendFulfillmentReceipt' => false],
            ],
            'orderCreate',
        );

        $created = $data['orderCreate']['order'] ?? null;

        if (! $created) {
            throw new ShopifyException('transport', 'Shopify returned no order');
        }

        return new CommerceResult(success: true, orderId: $this->numericId($created['id'] ?? null), orderNumber: $created['name'] ?? null);
    }

    /**
     * Shopify does not email the invoice here: the moderator sends the returned
     * invoiceUrl in the conversation.
     */
    public function createPaymentLink(OrderPayload $payload): CommerceResult
    {
        $currency = $payload->order->currency ?: 'EGP';
        $discount = $payload->discount;

        $input = array_filter([
            // priceOverride pins each line to the CRM's unit price so the invoice total matches the order.
            'lineItems' => array_map(fn (array $li) => [
                'variantId' => "gid://shopify/ProductVariant/{$li['variant_shopify_id']}",
                'quantity' => $li['qty'],
                'priceOverride' => ['amount' => $li['price'], 'currencyCode' => $currency],
            ], $payload->lineItems),
            'purchasingEntity' => $payload->customerId ? ['customerId' => "gid://shopify/Customer/{$payload->customerId}"] : null,
            'phone' => $payload->shippingAddress['phone'] ?? null,
            'shippingAddress' => $payload->shippingAddress !== [] ? $this->mailingAddress($payload->shippingAddress) : null,
            'shippingLine' => [
                'title' => $payload->shippingLine['title'] ?? ShippingQuote::DEFAULT_TITLE,
                'priceWithCurrency' => ['amount' => $payload->shippingLine['price'] ?? '0.00', 'currencyCode' => $currency],
            ],
            'appliedDiscount' => $discount === null ? null : array_filter([
                'value' => (float) $discount['value'],
                'valueType' => $discount['type'] === 'percent' ? 'PERCENTAGE' : 'FIXED_AMOUNT',
                'title' => 'Social CRM',
                'description' => $discount['reason'] ?? null,
            ], fn ($v) => $v !== null),
            'tags' => $payload->tags,
            'note' => $payload->note,
            'customAttributes' => $this->customAttributes($payload),
        ], fn ($v) => $v !== null);

        $data = $this->client->mutate(
            'mutation draftOrderCreate($input: DraftOrderInput!) { draftOrderCreate(input: $input) { draftOrder { id name invoiceUrl totalPriceSet { shopMoney { amount } } } userErrors { field message } } }',
            ['input' => $input],
            'draftOrderCreate',
        );

        $draft = $data['draftOrderCreate']['draftOrder'] ?? null;

        if (! $draft) {
            throw new ShopifyException('transport', 'Shopify returned no draft order');
        }

        return $this->draftResult($draft);
    }

    /**
     * A tag alone is not proof (final fix wave I3): the found node must carry
     * this order's `crm_order_id` custom attribute and must not predate the
     * local order (10 min clock-skew allowance). Unverified nodes are ignored.
     */
    public function findSubmittedOrder(Order $order): ?CommerceResult
    {
        $search = "tag:'".OrderPayload::tagFor($order->id)."'";

        if ($order->type === OrderType::PaymentLink) {
            $data = $this->client->query(
                'query draftByTag($query: String!) { draftOrders(first: 5, query: $query) { nodes { id name invoiceUrl createdAt customAttributes { key value } totalPriceSet { shopMoney { amount } } } } }',
                ['query' => $search],
            );

            $draft = $this->firstVerifiedNode($data['draftOrders']['nodes'] ?? [], $order);

            return $draft ? $this->draftResult($draft) : null;
        }

        $data = $this->client->query(
            'query orderByTag($query: String!) { orders(first: 5, query: $query) { nodes { id name createdAt customAttributes { key value } } } }',
            ['query' => $search],
        );

        $found = $this->firstVerifiedNode($data['orders']['nodes'] ?? [], $order);

        return $found
            ? new CommerceResult(success: true, orderId: $this->numericId($found['id'] ?? null), orderNumber: $found['name'] ?? null)
            : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function firstVerifiedNode(mixed $nodes, Order $order): ?array
    {
        $earliest = CarbonImmutable::instance($order->created_at ?? now())->subMinutes(10);

        foreach (is_array($nodes) ? $nodes : [] as $node) {
            if (! is_array($node) || blank($node['createdAt'] ?? null)) {
                continue;
            }

            $crmOrderId = collect($node['customAttributes'] ?? [])
                ->first(fn ($attr) => is_array($attr) && ($attr['key'] ?? null) === 'crm_order_id')['value'] ?? null;

            if ((string) $crmOrderId !== (string) $order->id) {
                continue;
            }

            try {
                $createdAt = CarbonImmutable::parse((string) $node['createdAt']);
            } catch (Throwable) {
                continue;
            }

            if ($createdAt->greaterThanOrEqualTo($earliest)) {
                return $node;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function draftResult(array $draft): CommerceResult
    {
        $amount = $draft['totalPriceSet']['shopMoney']['amount'] ?? null;

        return new CommerceResult(
            success: true,
            orderNumber: $draft['name'] ?? null,
            draftOrderId: $this->numericId($draft['id'] ?? null),
            invoiceUrl: $draft['invoiceUrl'] ?? null,
            total: $amount !== null ? number_format((float) $amount, 2, '.', '') : null,
        );
    }

    public function cancelOrder(Order $order, bool $restock = true): CommerceResult
    {
        try {
            // An unpaid payment link only exists as a draft: delete it.
            if ($order->shopify_order_id === null && $order->shopify_draft_order_id !== null) {
                $this->client->mutate(
                    'mutation draftOrderDelete($input: DraftOrderDeleteInput!) { draftOrderDelete(input: $input) { deletedId userErrors { field message } } }',
                    ['input' => ['id' => "gid://shopify/DraftOrder/{$order->shopify_draft_order_id}"]],
                    'draftOrderDelete',
                );

                return new CommerceResult(success: true, draftOrderId: $order->shopify_draft_order_id);
            }

            if ($order->shopify_order_id === null) {
                return new CommerceResult(success: true);
            }

            // orderCancel reports errors under `orderCancelUserErrors`, which mutate() does not inspect.
            $data = $this->client->query(
                'mutation orderCancel($orderId: ID!, $reason: OrderCancelReason!, $refund: Boolean!, $restock: Boolean!) { orderCancel(orderId: $orderId, reason: $reason, refund: $refund, restock: $restock) { job { id } orderCancelUserErrors { field message } } }',
                ['orderId' => "gid://shopify/Order/{$order->shopify_order_id}", 'reason' => 'CUSTOMER', 'refund' => false, 'restock' => $restock],
            );

            $errors = $data['orderCancel']['orderCancelUserErrors'] ?? [];

            if ($errors !== []) {
                return new CommerceResult(success: false, error: collect($errors)->pluck('message')->implode('; '));
            }

            return new CommerceResult(success: true, orderId: $order->shopify_order_id);
        } catch (ShopifyException $e) {
            $messages = collect($e->userErrors)->pluck('message')->filter()->implode('; ');

            return new CommerceResult(success: false, error: $messages !== '' ? $messages : $e->getMessage());
        }
    }

    public function syncProducts(): int
    {
        $query = <<<'GRAPHQL'
            query products($cursor: String) {
              products(first: 100, after: $cursor) {
                pageInfo { hasNextPage endCursor }
                nodes {
                  id
                  title
                  handle
                  featuredImage { url }
                  variants(first: 50) {
                    nodes { id sku title price inventoryQuantity }
                  }
                }
              }
            }
        GRAPHQL;

        $count = 0;
        $cursor = null;

        do {
            $products = $this->client->query($query, ['cursor' => $cursor])['products'] ?? null;

            if ($products === null) {
                break;
            }

            foreach ($products['nodes'] as $node) {
                $this->upsertProduct($node);
                $count++;
            }

            $cursor = $products['pageInfo']['hasNextPage'] ? $products['pageInfo']['endCursor'] : null;
        } while ($cursor !== null);

        return $count;
    }

    /**
     * Verifies against the stored integration's own api_secret when an
     * integration row exists (any status — a disconnected/error row still owns
     * the secret Shopify signs with), falling back to the static config secret
     * only when no integration has ever been saved (plan Global Constraints,
     * spec §4.2 point 1).
     */
    public function verifyWebhook(Request $request): bool
    {
        $integrationSecret = $this->integrations->current()?->api_secret;
        $secret = filled($integrationSecret) ? $integrationSecret : config('crm.shopify.webhook_secret');

        if (empty($secret)) {
            return false;
        }

        $header = (string) $request->header('X-Shopify-Hmac-Sha256', '');
        $computed = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        return $header !== '' && hash_equals($computed, $header);
    }

    public function parseWebhook(string $topic, array $payload): ?OrderStatusUpdate
    {
        return match ($topic) {
            'orders/paid', 'orders/updated', 'orders/cancelled' => new OrderStatusUpdate(
                shopifyOrderId: isset($payload['id']) ? (string) $payload['id'] : null,
                draftOrderId: null,
                financialStatus: $payload['financial_status'] ?? null,
                fulfillmentStatus: $payload['fulfillment_status'] ?? null,
                cancelled: $topic === 'orders/cancelled' || ! empty($payload['cancelled_at']),
                orderNumber: $payload['name'] ?? null,
                crmOrderId: $this->crmOrderId($payload),
            ),
            'draft_orders/update' => new OrderStatusUpdate(
                shopifyOrderId: isset($payload['order_id']) ? (string) $payload['order_id'] : null,
                draftOrderId: isset($payload['id']) ? (string) $payload['id'] : null,
                financialStatus: ! empty($payload['order_id']) ? 'paid' : null,
                fulfillmentStatus: null,
                cancelled: false,
                orderNumber: $payload['name'] ?? null,
                crmOrderId: $this->crmOrderId($payload),
            ),
            default => null,
        };
    }

    /**
     * orderCreate has no `appliedDiscount`; an order-level discount is sent as a
     * custom discount code over the line items.
     *
     * @return array<string, mixed>|null
     */
    private function orderDiscountCode(OrderPayload $payload, string $currency): ?array
    {
        $discount = $payload->discount;

        if ($discount === null) {
            return null;
        }

        return $discount['type'] === 'percent'
            ? ['itemPercentageDiscountCode' => ['code' => self::DISCOUNT_CODE, 'percentage' => (float) $discount['value']]]
            : ['itemFixedDiscountCode' => ['code' => self::DISCOUNT_CODE, 'amountSet' => ['shopMoney' => ['amount' => $discount['amount'], 'currencyCode' => $currency]]]];
    }

    /**
     * @param  array<string, string>  $address
     * @return array<string, string>
     */
    private function mailingAddress(array $address): array
    {
        return array_intersect_key($address, array_flip(['firstName', 'lastName', 'phone', 'address1', 'city', 'provinceCode', 'countryCode']));
    }

    /**
     * Shopify returns order custom attributes as `note_attributes: [{name, value}]`.
     */
    private function crmOrderId(array $payload): ?string
    {
        foreach ($payload['note_attributes'] ?? [] as $attribute) {
            if (($attribute['name'] ?? null) === 'crm_order_id' && ($attribute['value'] ?? '') !== '') {
                return (string) $attribute['value'];
            }
        }

        return null;
    }

    /**
     * @return array<int, array{key: string, value: string}>
     */
    private function customAttributes(OrderPayload $payload): array
    {
        return array_map(
            fn ($key, $value) => ['key' => $key, 'value' => (string) $value],
            array_keys($payload->noteAttributes),
            array_values($payload->noteAttributes),
        );
    }

    private function numericId(?string $gid): ?string
    {
        if ($gid === null || $gid === '') {
            return null;
        }

        $parts = explode('/', $gid);

        return end($parts) ?: null;
    }

    /**
     * @param  array{id: string, title: string, handle: string, featuredImage?: array{url?: string}, variants: array{nodes: array<int, array<string, mixed>>}}  $node
     */
    private function upsertProduct(array $node): void
    {
        $product = Product::updateOrCreate(
            ['shopify_id' => $this->numericId($node['id'])],
            [
                'title' => $node['title'],
                'handle' => $node['handle'],
                'image_url' => $node['featuredImage']['url'] ?? null,
                'status' => 'active',
            ],
        );

        foreach ($node['variants']['nodes'] ?? [] as $variant) {
            ProductVariant::updateOrCreate(
                ['shopify_id' => $this->numericId($variant['id'])],
                [
                    'product_id' => $product->id,
                    'sku' => $variant['sku'] ?? null,
                    'title' => $variant['title'] ?? 'Default',
                    'price' => $variant['price'] ?? 0,
                    'inventory_quantity' => $variant['inventoryQuantity'] ?? 0,
                ],
            );
        }
    }
}
