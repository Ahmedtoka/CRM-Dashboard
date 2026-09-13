<?php

namespace App\Commerce\Http;

use App\Commerce\Contracts\CommerceProvider;
use App\Commerce\Jobs\ProcessShopifyWebhook;
use App\Http\Controllers\Controller;
use App\Models\ShopifyWebhookSubscription;
use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shopify order webhooks go through the same store → dedupe → queue pipeline as
 * the channel webhooks, so a slow/failed order update never makes Shopify retry
 * (or give up on) the delivery, and every event is visible in webhook_events.
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

    public function __construct(private readonly CommerceProvider $provider) {}

    public function handle(Request $request, string $topic): Response
    {
        if (! $this->provider->verifyWebhook($request)) {
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
}
