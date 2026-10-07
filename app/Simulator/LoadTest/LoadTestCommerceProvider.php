<?php

namespace App\Simulator\LoadTest;

use App\Commerce\Contracts\CommerceProvider;
use App\Commerce\Data\CommerceResult;
use App\Commerce\Data\OrderPayload;
use App\Commerce\Data\OrderStatusUpdate;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The store behind every order taken in a load-test chat (2026-10-07), whatever the global
 * commerce driver: OrderService::providerFor() sends a load-test order here. Nothing leaves the
 * system — no Shopify customer, order, draft, payment link or stock movement. Its ids are
 * prefixed `loadtest-` (never a Shopify id) and its order numbers sit in the load test's own
 * range (Scenarios::ORDER_NUMBER_BASE), so they can never be mistaken for a real order.
 * Stateless (no static log): it runs inside the long-lived production workers.
 */
class LoadTestCommerceProvider implements CommerceProvider
{
    public const ID_PREFIX = 'loadtest-';

    public function ensureCustomer(Customer $customer, array $shippingAddress): string
    {
        return self::ID_PREFIX.'customer-'.$customer->id;
    }

    /** Nothing was ever created anywhere: a retry creates its test order again. */
    public function findSubmittedOrder(Order $order): ?CommerceResult
    {
        return null;
    }

    public function createCodOrder(OrderPayload $payload): CommerceResult
    {
        return new CommerceResult(
            success: true,
            orderId: self::ID_PREFIX.'order-'.$payload->order->id,
            orderNumber: '#'.self::orderNumber($payload->order),
        );
    }

    public function createPaymentLink(OrderPayload $payload): CommerceResult
    {
        return new CommerceResult(
            success: true,
            orderNumber: '#'.self::orderNumber($payload->order),
            draftOrderId: self::ID_PREFIX.'draft-'.$payload->order->id,
            // A link that goes nowhere (.invalid never resolves); the simulated customer never opens it.
            invoiceUrl: 'https://pay.loadtest.invalid/'.$payload->order->id.'/'.Str::lower(Str::random(16)),
            total: number_format((float) $payload->order->total, 2, '.', ''),
        );
    }

    public function cancelOrder(Order $order, bool $restock = true): CommerceResult
    {
        return new CommerceResult(success: true, orderId: $order->shopify_order_id, draftOrderId: $order->shopify_draft_order_id);
    }

    public function syncProducts(): int
    {
        return 0;
    }

    public function verifyWebhook(Request $request): bool
    {
        return false;
    }

    public function parseWebhook(string $topic, array $payload): ?OrderStatusUpdate
    {
        return null;
    }

    /** A test order's number: in the load test's own range, so it can never collide with a real one. */
    public static function orderNumber(Order $order): string
    {
        return (string) (Scenarios::ORDER_NUMBER_BASE + 500000 + $order->id);
    }
}
