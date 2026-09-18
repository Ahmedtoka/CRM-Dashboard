<?php

namespace App\Shopify\Webhooks;

use App\Commerce\Contracts\CommerceProvider;
use App\Commerce\OrderService;
use App\Enums\OrderSource;
use App\Enums\UserRole;
use App\Events\UserNotified;
use App\Models\Order;
use App\Models\User;
use App\Shopify\Connection\IntegrationRepository;
use App\Shopify\Connection\ShopifyIntegration;
use App\Shopify\Sync\Mappers\CustomerMapper;
use App\Shopify\Sync\Mappers\InventoryMapper;
use App\Shopify\Sync\Mappers\MapResult;
use App\Shopify\Sync\Mappers\OrderMapper;
use App\Shopify\Sync\Mappers\ProductMapper;
use App\Support\SafeBroadcast;

/**
 * Routes a stored, verified Shopify webhook to its mapper (spec §4.2). One
 * instance handles every topic in `config('crm.shopify.webhook_topics')`;
 * called by ProcessShopifyWebhook on the `commerce` queue.
 */
final class ShopifyWebhookProcessor
{
    public function __construct(
        private readonly ProductMapper $products,
        private readonly InventoryMapper $inventory,
        private readonly CustomerMapper $customers,
        private readonly OrderMapper $orders,
        private readonly OrderService $orderService,
        private readonly CommerceProvider $provider,
        private readonly IntegrationRepository $integrations,
    ) {}

    public function process(string $topic, array $payload): void
    {
        match ($topic) {
            'products/create', 'products/update' => $this->products->upsert($payload),
            'products/delete' => $this->handleProductDelete($payload),
            'inventory_levels/update' => $this->inventory->apply($payload),
            'customers/create', 'customers/update' => $this->customers->upsert($payload),
            'orders/create', 'orders/updated', 'orders/paid' => $this->routeOrderUpsert($payload),
            'orders/cancelled' => $this->routeOrderCancelled($payload),
            'fulfillments/create', 'fulfillments/update' => $this->orders->applyFulfillment($payload),
            'refunds/create' => $this->orders->applyRefund($payload),
            'draft_orders/update' => $this->routeDraftOrderUpdate($payload),
            'app/uninstalled' => $this->handleUninstalled(),
            default => null,
        };
    }

    private function handleProductDelete(array $payload): void
    {
        $id = $payload['id'] ?? null;

        if ($id === null || $id === '') {
            return;
        }

        $this->products->delete((string) $id);
    }

    /**
     * OrderMapper::upsert's chat-order path never moves the order's status or
     * touches paid_at (see OrderMapper::updateChatOrder) — only Shopify ids,
     * name, financial/fulfillment statuses and shopify_updated_at change there.
     * So after it runs, this drives the real transition itself:
     *  - a cancelled_at present on the payload goes through OrderService::cancel
     *    (no provider sync — the cancel came from Shopify);
     *  - otherwise a paid/partially_paid financial status goes through
     *    OrderService::markPaid.
     * Both lock the row and re-check its status inside their own transaction
     * (idempotent under concurrent/duplicate delivery), and give the order the
     * side effects (shipment, customer stats, activity log, broadcast) that only
     * OrderService performs, regardless of which of orders/create,
     * orders/updated or orders/paid carried the signal. A payload the mapper
     * skipped as stale never drives a transition (final fix wave I5).
     */
    private function routeOrderUpsert(array $payload): void
    {
        if ($this->orders->upsert($payload) === MapResult::Skipped) {
            return;
        }

        $order = $this->findChatOrder($payload);

        if ($order === null) {
            return;
        }

        // A cancellation can also arrive as orders/updated (Shopify doesn't
        // guarantee orders/cancelled fires separately in every case); treat it
        // the same as the dedicated topic whenever the payload carries it.
        if (! blank($payload['cancelled_at'] ?? null)) {
            $this->orderService->cancel($order, null, syncProvider: false);

            return;
        }

        $financial = strtolower((string) ($payload['financial_status'] ?? ''));

        if (! in_array($financial, ['paid', 'partially_paid'], true)) {
            return;
        }

        $this->orderService->markPaid($order);
    }

    private function routeOrderCancelled(array $payload): void
    {
        if ($this->orders->markCancelled($payload) === MapResult::Skipped) {
            return;
        }

        $order = $this->findChatOrder($payload);

        if ($order !== null) {
            $this->orderService->cancel($order, null, syncProvider: false);
        }
    }

    /**
     * draft_orders/update stays on the pre-existing pipeline (plan interface,
     * "keep OrderService::applyUpdate behaviour"): a draft's conversion to a
     * real order is the one case OrderMapper does not model directly.
     */
    private function routeDraftOrderUpdate(array $payload): void
    {
        $update = $this->provider->parseWebhook('draft_orders/update', $payload);

        if ($update !== null) {
            $this->orderService->applyUpdate($update);
        }
    }

    /**
     * Mirrors OrderMapper::findLocal's crm_order_id/shopify_order_id/draft_order_id
     * lookup, but only ever returns a Chat-sourced order — a store payload must
     * never be treated as a CRM-created order here.
     */
    private function findChatOrder(array $payload): ?Order
    {
        $shopifyId = isset($payload['id']) ? (string) $payload['id'] : null;

        $crmOrderId = collect($payload['note_attributes'] ?? [])
            ->first(fn ($attr) => ($attr['name'] ?? null) === 'crm_order_id')['value'] ?? null;

        if (is_numeric($crmOrderId) && (int) $crmOrderId > 0) {
            $candidate = Order::find((int) $crmOrderId);

            if ($candidate !== null
                && $candidate->source === OrderSource::Chat
                && ($candidate->shopify_order_id === null || $candidate->shopify_order_id === $shopifyId)) {
                return $candidate;
            }
        }

        if ($shopifyId !== null) {
            $bySid = Order::where('shopify_order_id', $shopifyId)->first();

            if ($bySid !== null && $bySid->source === OrderSource::Chat) {
                return $bySid;
            }
        }

        $draftId = isset($payload['draft_order_id'])
            ? (string) $payload['draft_order_id']
            : (isset($payload['draft_order']['id']) ? (string) $payload['draft_order']['id'] : null);

        if ($draftId !== null) {
            $byDraft = Order::where('shopify_draft_order_id', $draftId)->first();

            if ($byDraft !== null && $byDraft->source === OrderSource::Chat) {
                return $byDraft;
            }
        }

        return null;
    }

    /**
     * Idempotent (spec §4.2 point 7): a re-delivered app/uninstalled just
     * re-confirms `disconnected`. Admins are notified only on the transition.
     */
    private function handleUninstalled(): void
    {
        $integration = $this->integrations->current();

        if ($integration === null) {
            return;
        }

        $alreadyDisconnected = $integration->status === 'disconnected';

        $integration->forceFill(['status' => 'disconnected'])->save();

        if (! $alreadyDisconnected) {
            $this->notifyAdminsOfUninstall($integration);
        }
    }

    private function notifyAdminsOfUninstall(ShopifyIntegration $integration): void
    {
        User::query()
            ->where('is_active', true)
            ->where('role', UserRole::Admin->value)
            ->get()
            ->each(fn (User $u) => SafeBroadcast::send(new UserNotified($u->id, 'shopify.uninstalled', [
                'shop_domain' => $integration->shop_domain,
            ])));
    }
}
