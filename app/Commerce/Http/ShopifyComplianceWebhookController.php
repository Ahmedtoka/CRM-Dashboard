<?php

namespace App\Commerce\Http;

use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Shopify\App\ShopifyCompliance;
use App\Shopify\App\WebhookSignature;
use App\Shopify\Jobs\ProcessShopifyCompliance;
use App\Webhooks\WebhookRejections;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The three mandatory privacy webhooks of the Partners app (shopify.app.toml compliance_topics).
 * Shopify signs them with the app's client secret only, and expects 401 for a bad signature and a
 * fast 200 otherwise: the event is stored and handled on the `commerce` queue (ShopifyCompliance).
 */
class ShopifyComplianceWebhookController extends Controller
{
    public function __construct(private readonly WebhookSignature $signature) {}

    public function __invoke(Request $request): Response
    {
        $verifiedWith = $this->signature->verify($request, appsOnly: true);

        if ($verifiedWith === null) {
            $reason = match (true) {
                ! $this->signature->hasAnySecret(appsOnly: true) => 'no_secret',
                ! $request->headers->has('X-Shopify-Hmac-Sha256') => 'missing_hmac',
                default => 'bad_hmac',
            };
            app(WebhookRejections::class)->record('shopify', $request->path(), $reason, $request->ip());

            return response('Unauthorized', 401);
        }

        $topic = (string) $request->header('X-Shopify-Topic', '');

        if (! in_array($topic, ShopifyCompliance::TOPICS, true)) {
            return response('Unknown compliance topic', 400);
        }

        $raw = $request->getContent();
        $payload = json_decode($raw, true);
        $payload = is_array($payload) ? $payload : [];
        // What Shopify says it delivered for; the job compares it with the connected store.
        $payload['_shop_domain_header'] = (string) $request->header('X-Shopify-Shop-Domain', '');

        try {
            $event = WebhookEvent::firstOrCreate(
                ['provider' => 'shopify', 'dedupe_key' => (string) ($request->header('X-Shopify-Webhook-Id') ?: sha1($topic."\n".$raw))],
                [
                    'event_type' => $topic,
                    'payload' => $payload,
                    'signature_valid' => true,
                    'status' => 'received',
                    'verified_with' => $verifiedWith,
                ],
            );
        } catch (UniqueConstraintViolationException) {
            return response('ok', 200);
        }

        if ($event->wasRecentlyCreated) {
            ProcessShopifyCompliance::dispatch($event->id);
        }

        return response('ok', 200);
    }
}
