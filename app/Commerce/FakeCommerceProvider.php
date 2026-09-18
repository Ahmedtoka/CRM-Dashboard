<?php

namespace App\Commerce;

use App\Commerce\Contracts\CommerceProvider;
use App\Commerce\Data\CommerceResult;
use App\Commerce\Data\OrderPayload;
use App\Commerce\Data\OrderStatusUpdate;
use App\Enums\OrderType;
use App\Models\Customer;
use App\Models\Order;
use App\Shopify\Client\ShopifyException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Deterministic in-memory commerce driver used in tests and the fake demo
 * (crm.drivers.commerce = 'fake'). Records every payload it receives so
 * tests can assert on tags/note/line items without hitting Shopify.
 */
class FakeCommerceProvider implements CommerceProvider
{
    /** @var array<int, OrderPayload> */
    public static array $payloads = [];

    /**
     * When set, createCodOrder()/createPaymentLink() fail with this message
     * instead of succeeding — lets tests exercise the provider-failure path.
     */
    public static ?string $forceError = null;

    /** @var array<int, int> ids of orders passed to cancelOrder() */
    public static array $cancelled = [];

    /** @var array<int, array{order_id: int, mode: string, restock: bool}> */
    public static array $cancelCalls = [];

    /** When set, cancelOrder() fails with this message. */
    public static ?string $forceCancelError = null;

    /** @var array<int, array{customer_id: int, address: array<string, string>}> customers created on the "store" */
    public static array $customers = [];

    /** @var array<int, array{order_id: int, tag: string, type: string, result: CommerceResult}> orders/drafts created on the "store" */
    public static array $created = [];

    /** Number of findSubmittedOrder() lookups. */
    public static int $lookups = 0;

    /** When set, the draft's store total reported by createPaymentLink() (to simulate a price mismatch). */
    public static ?string $draftTotalOverride = null;

    /** Runs at the start of createCodOrder()/createPaymentLink() (e.g. to race a cancel). */
    public static ?Closure $beforeCreate = null;

    /** @var array{kind: string, message: string, lineIndex: ?int, afterCreate: bool}|null */
    private static ?array $failNext = null;

    public static function reset(): void
    {
        self::$payloads = [];
        self::$forceError = null;
        self::$cancelled = [];
        self::$cancelCalls = [];
        self::$forceCancelError = null;
        self::$customers = [];
        self::$created = [];
        self::$lookups = 0;
        self::$draftTotalOverride = null;
        self::$beforeCreate = null;
        self::$failNext = null;
    }

    /**
     * The next order/payment-link creation throws like the live driver would:
     * a ShopifyException for auth/not_connected/throttled/transport/graphql/user_errors
     * (user errors point at `lineItems.{lineIndex}` when given), anything else a
     * plain RuntimeException. With $afterCreate the store keeps the order and the
     * failure happens after it (a timeout on the response).
     */
    public static function failNext(string $kind, string $message, ?int $lineIndex = null, bool $afterCreate = false): void
    {
        self::$failNext = ['kind' => $kind, 'message' => $message, 'lineIndex' => $lineIndex, 'afterCreate' => $afterCreate];
    }

    public function ensureCustomer(Customer $customer, array $shippingAddress): string
    {
        if (! empty($customer->shopify_customer_id)) {
            return (string) $customer->shopify_customer_id;
        }

        self::$customers[] = ['customer_id' => $customer->id, 'address' => $shippingAddress];

        return (string) (700000 + $customer->id);
    }

    public function findSubmittedOrder(Order $order): ?CommerceResult
    {
        self::$lookups++;

        $type = $order->type === OrderType::PaymentLink ? 'draft' : 'order';

        foreach (self::$created as $row) {
            if ($row['tag'] === OrderPayload::tagFor($order->id) && $row['type'] === $type) {
                return $row['result'];
            }
        }

        return null;
    }

    public function createCodOrder(OrderPayload $payload): CommerceResult
    {
        $this->beforeCreate($payload);

        if (self::$forceError !== null) {
            return new CommerceResult(success: false, error: self::$forceError);
        }

        return $this->created($payload, 'order', new CommerceResult(
            success: true,
            orderId: (string) (900000 + $payload->order->id),
            orderNumber: '#'.(1000 + $payload->order->id),
        ));
    }

    public function createPaymentLink(OrderPayload $payload): CommerceResult
    {
        $this->beforeCreate($payload);

        if (self::$forceError !== null) {
            return new CommerceResult(success: false, error: self::$forceError);
        }

        $token = Str::lower(Str::random(20));

        return $this->created($payload, 'draft', new CommerceResult(
            success: true,
            orderNumber: '#D'.$payload->order->id,
            draftOrderId: (string) (800000 + $payload->order->id),
            invoiceUrl: "https://fake-shop.myshopify.com/{$payload->order->id}/invoices/{$token}",
            total: self::$draftTotalOverride ?? $this->payloadTotal($payload),
        ));
    }

    public function cancelOrder(Order $order, bool $restock = true): CommerceResult
    {
        self::$cancelled[] = $order->id;
        self::$cancelCalls[] = [
            'order_id' => $order->id,
            'mode' => $order->shopify_order_id !== null ? 'order' : ($order->shopify_draft_order_id !== null ? 'draft' : 'none'),
            'restock' => $restock,
        ];

        if (self::$forceCancelError !== null) {
            return new CommerceResult(success: false, error: self::$forceCancelError);
        }

        return new CommerceResult(success: true, orderId: $order->shopify_order_id, draftOrderId: $order->shopify_draft_order_id);
    }

    public function syncProducts(): int
    {
        return 0;
    }

    public function verifyWebhook(Request $request): bool
    {
        return true;
    }

    public function parseWebhook(string $topic, array $payload): ?OrderStatusUpdate
    {
        return null;
    }

    private function beforeCreate(OrderPayload $payload): void
    {
        self::$payloads[] = $payload;

        if (self::$beforeCreate !== null) {
            (self::$beforeCreate)($payload->order);
        }

        if (self::$failNext !== null && ! self::$failNext['afterCreate']) {
            $this->throwPendingFailure();
        }
    }

    private function created(OrderPayload $payload, string $type, CommerceResult $result): CommerceResult
    {
        self::$created[] = ['order_id' => $payload->order->id, 'tag' => OrderPayload::tagFor($payload->order->id), 'type' => $type, 'result' => $result];

        if (self::$failNext !== null && self::$failNext['afterCreate']) {
            $this->throwPendingFailure();
        }

        return $result;
    }

    private function throwPendingFailure(): never
    {
        ['kind' => $kind, 'message' => $message, 'lineIndex' => $lineIndex] = self::$failNext;
        self::$failNext = null;

        if (! in_array($kind, ['auth', 'not_connected', 'throttled', 'transport', 'graphql', 'user_errors'], true)) {
            throw new RuntimeException($message);
        }

        $userErrors = $kind === 'user_errors'
            ? [['field' => $lineIndex === null ? ['order', 'phone'] : ['order', 'lineItems', (string) $lineIndex], 'message' => $message]]
            : [];

        throw new ShopifyException($kind, $message, $userErrors);
    }

    /** What the store would total the draft at: lines + shipping − discount. */
    private function payloadTotal(OrderPayload $payload): string
    {
        $piastres = 0;

        foreach ($payload->lineItems as $li) {
            $piastres += (int) round((float) $li['price'] * 100) * (int) $li['qty'];
        }

        $piastres += (int) round((float) ($payload->shippingLine['price'] ?? 0) * 100);
        $piastres -= (int) round((float) ($payload->discount['amount'] ?? 0) * 100);

        return number_format(max(0, $piastres) / 100, 2, '.', '');
    }
}
