<?php

namespace App\Commerce\Http;

use App\Commerce\Jobs\ProcessShopifyWebhook;
use App\Http\Controllers\Controller;
use App\Models\ShopifyWebhookSubscription;
use App\Models\WebhookEvent;
use App\Shopify\App\WebhookSignature;
use App\Shopify\Connection\IntegrationRepository;
use App\Webhooks\WebhookRejections;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shopify order webhooks go through the same store → dedupe → queue pipeline as
 * the channel webhooks, so a slow/failed order update never makes Shopify retry
 * (or give up on) the delivery, and every event is visible in webhook_events.
 *
 * Authentication does not depend on the commerce driver (final fix wave C1):
 * every topic runs through the real mappers, so an unsigned POST must never be
 * able to create orders, merge customers or disconnect the store just because
 * CRM_COMMERCE_DRIVER is `fake`.
 */
class ShopifyWebhookController extends Controller
{
    /**
     * URL topic segment (hyphenated) -> Shopify's own webhook topic string.
     */
    private const TOPIC_MAP = [
        'orders-paid' => 'orders/paid',
        'orders-updated' => 'orders/updated',
        'orders-cancelled' => 'orders/cancelled',
        'draft-orders-update' => 'draft_orders/update',
    ];

    public function __construct(
        private readonly IntegrationRepository $integrations,
        private readonly WebhookSignature $signature,
    ) {}

    public function handle(Request $request, string $topic): Response
    {
        $verifiedWith = $this->signature->verify($request);
        $wrongShop = $verifiedWith !== null && ! $this->fromConnectedShop($request);

        if ($verifiedWith === null || $wrongShop) {
            // Shown on Settings → Shopify (2026-10-17): every webhook was once refused with nobody seeing it.
            app(WebhookRejections::class)->record('shopify', $request->path(), $wrongShop ? 'wrong_shop' : $this->rejectionReason($request), $request->ip());

            return response('Unauthorized', 401);
        }

        $raw = $request->getContent();
        $shopifyTopic = self::TOPIC_MAP[$topic] ?? str_replace('-', '/', $topic);

        // Shopify sends a unique id per delivery attempt group; without it, fall back
        // to the body hash (scoped by topic, since orders/paid and orders/updated can
        // carry identical bodies).
        $dedupeKey = (string) ($request->header('X-Shopify-Webhook-Id') ?: sha1($shopifyTopic."\n".$raw));

        // Best-effort health signal for the connection screen; a topic with no
        // local subscription row (e.g. an unregistered/unknown topic) is a no-op.
        ShopifyWebhookSubscription::where('topic', $shopifyTopic)->update(['last_received_at' => now()]);

        try {
            $event = WebhookEvent::firstOrCreate(
                ['provider' => 'shopify', 'dedupe_key' => $dedupeKey],
                [
                    'event_type' => $shopifyTopic,
                    'payload' => json_decode($raw, true) ?? [],
                    'signature_valid' => true,
                    'status' => 'received',
                    'verified_with' => $verifiedWith,
                ],
            );
        } catch (UniqueConstraintViolationException) {
            return response('ok', 200); // concurrent duplicate delivery
        }

        if ($event->wasRecentlyCreated) {
            ProcessShopifyWebhook::dispatch($event->id)->onQueue('commerce');
        }

        return response('ok', 200);
    }

    private function rejectionReason(Request $request): string
    {
        return match (true) {
            ! $this->signature->hasAnySecret() => 'no_secret',
            ! $request->headers->has('X-Shopify-Hmac-Sha256') => 'missing_hmac',
            default => 'bad_hmac',
        };
    }

    /**
     * `X-Shopify-Shop-Domain` must be the connected store (round 2026-10-17): an app secret signs
     * for every store the app is installed on. A delivery without the header (the simulator, old
     * tests) is judged by its signature alone; with no integration row there is nothing to compare.
     */
    private function fromConnectedShop(Request $request): bool
    {
        $header = trim((string) $request->header('X-Shopify-Shop-Domain', ''));
        $shop = $this->integrations->current()?->shop_domain;

        return $header === '' || blank($shop) || strcasecmp($header, (string) $shop) === 0;
    }
}
