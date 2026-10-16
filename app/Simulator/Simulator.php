<?php

namespace App\Simulator;

use App\Channels\Jobs\ProcessWebhookEvent;
use App\Commerce\Jobs\ProcessShopifyWebhook;
use App\Enums\MessageStatus;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use App\Models\Comment;
use App\Models\Message;
use App\Models\Order;
use App\Models\WebhookEvent;
use App\Simulator\LoadTest\LoadTestChannels;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Seeders\Demo\ArabicCorpus;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Demo traffic generator (Task 11, spec §10). Every method posts through the
 * same webhook pipeline a real platform would use — a `WebhookEvent` row plus
 * `ProcessWebhookEvent::dispatchSync()` — so ingestion, bot decisions, comment
 * handling and attribution/activity logs are produced by the real services,
 * not synthesized separately. Used by both `crm:simulate` (live traffic) and
 * `DemoSeeder` (30-day historical replay).
 */
class Simulator
{
    /**
     * Number of events in the most recent burst() call that were sent and
     * ingested successfully but whose real-time broadcast failed (e.g. no
     * Reverb server reachable). Ingestion/bot data for those events is still
     * fully persisted — only the live websocket push was lost.
     */
    private int $lastBurstBroadcastFailures = 0;

    /**
     * Sends a customer message on $p from $customerKey and returns the
     * persisted inbound Message once ingestion (and, synchronously when the
     * queue is `sync`, the bot's reaction) has completed.
     *
     * @param  array<string, mixed>  $extra  more fields for the fake event (`referral`)
     * @param  array<string, mixed>|null  $loadTest  tags the conversation's `meta.load_test` once ingested
     */
    public function customerMessage(Platform $p, string $customerKey, string $name, string $text, ?CarbonInterface $at = null, ?string $attachment = null, array $extra = [], ?array $loadTest = null): Message
    {
        $at ??= now();

        $externalId = 'sim_msg_'.Str::uuid();

        $event = $this->createWebhookEvent($p, 'message', [$this->messagePayload($p, $externalId, $customerKey, $name, $text, $at, $attachment, $extra)], $loadTest);

        ProcessWebhookEvent::dispatchSync($event->id);

        return Message::where('platform', $p->value)->where('external_id', $externalId)->firstOrFail();
    }

    /**
     * Sends an inbound comment on a post ($postKey is stable per post: the
     * same key always resolves to the same `Post` row) and returns the
     * persisted Comment once the comment bot has run.
     */
    public function comment(Platform $p, string $postKey, string $customerKey, string $name, string $text, bool $isAd = false, ?CarbonInterface $at = null): Comment
    {
        $at ??= now();

        $commentExternalId = 'sim_c_'.Str::uuid();

        $event = $this->createWebhookEvent($p, 'comment', [[
            'type' => 'comment',
            'channel_id' => $this->ensureAccount($p)->external_id,
            'post_id' => $postKey,
            'post_caption' => 'Post '.$postKey,
            'is_ad' => $isAd,
            'comment_id' => $commentExternalId,
            'customer_id' => $customerKey,
            'name' => $name,
            'text' => $text,
            'at' => CarbonImmutable::instance($at)->utc()->toIso8601String(),
        ]]);

        ProcessWebhookEvent::dispatchSync($event->id);

        return Comment::where('external_id', $commentExternalId)->firstOrFail();
    }

    /**
     * Sends a delivery receipt for a previously sent outbound message.
     */
    public function receipt(Message $m, MessageStatus $status): void
    {
        $platform = $m->platform instanceof Platform ? $m->platform : Platform::from((string) $m->platform);

        $event = $this->createWebhookEvent($platform, 'receipt', [[
            'type' => 'receipt',
            'channel_id' => $this->ensureAccount($platform)->external_id,
            'message_id' => $m->external_id,
            'status' => $status->value,
            'at' => now()->toIso8601String(),
        ]]);

        ProcessWebhookEvent::dispatchSync($event->id);
    }

    /**
     * Fires $count random customer messages across $platforms. When $seconds
     * is 0 they run back-to-back (used for throughput measurement); when
     * positive, they're paced out over roughly that many wall-clock seconds
     * (used for a live/manual demo run).
     *
     * @param  array<int, Platform|string>  $platforms
     */
    public function burst(int $count, int $seconds, array $platforms): int
    {
        $this->lastBurstBroadcastFailures = 0;

        if ($count <= 0 || $platforms === []) {
            return 0;
        }

        $platformEnums = array_values(array_map(
            fn ($p) => $p instanceof Platform ? $p : Platform::from((string) $p),
            $platforms,
        ));

        $texts = ArabicCorpus::customerOpeners();
        $names = ArabicCorpus::names();
        $pace = ($count > 1 && $seconds > 0) ? $seconds / $count : 0.0;

        for ($i = 0; $i < $count; $i++) {
            $platform = $platformEnums[array_rand($platformEnums)];
            $n = random_int(1, 5000);

            try {
                $this->customerMessage(
                    $platform,
                    'sim-burst-'.$n,
                    $names[array_rand($names)],
                    $texts[array_rand($texts)],
                );
            } catch (BroadcastException $e) {
                // Real-time broadcasting (e.g. Reverb) isn't reachable — the
                // event was still ingested (its DB transaction had already
                // committed before the broadcast attempt), so a load/demo
                // burst should keep going rather than abort on an infra
                // hiccup unrelated to ingestion correctness.
                $this->lastBurstBroadcastFailures++;
                Log::warning('simulator.burst.broadcast_failed', ['message' => $e->getMessage()]);
            }

            if ($pace > 0) {
                usleep((int) round($pace * 1_000_000));
            }
        }

        return $count;
    }

    /**
     * A brand-new store order arriving from Shopify (orders/create, placed now), stored and
     * processed exactly like a real webhook so the WhatsApp order confirmation trigger runs
     * (spec 2026-10-16). Local and testing only: a fake order must never reach a real store's data.
     * It carries Shopify's `test` flag unless $test is false, so the trigger skips it by default.
     */
    public function shopifyOrderCreated(string $phone, ?string $name = null, bool $test = true): Order
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Fake Shopify orders are for local and testing only.');
        }

        $id = (int) (now()->format('U').random_int(100, 999));
        $name = trim((string) $name) !== '' ? trim((string) $name) : ArabicCorpus::names()[array_rand(ArabicCorpus::names())];
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');
        $at = now()->toIso8601String();
        $address = ['first_name' => $first, 'last_name' => $last, 'name' => $name, 'address1' => '12 شارع عباس العقاد', 'city' => 'مدينة نصر', 'province' => 'Cairo', 'country' => 'Egypt', 'country_code' => 'EG', 'phone' => $phone];

        $event = WebhookEvent::create([
            'provider' => 'shopify',
            'event_type' => 'orders/create',
            'dedupe_key' => 'sim-shopify-'.Str::uuid(),
            'received_at' => now(),
            'payload' => [
                'id' => $id,
                'admin_graphql_api_id' => 'gid://shopify/Order/'.$id,
                'name' => '#'.substr((string) $id, -6),
                'test' => $test,
                'created_at' => $at,
                'processed_at' => $at,
                'updated_at' => $at,
                'cancelled_at' => null,
                'currency' => 'EGP',
                'financial_status' => 'pending',
                'fulfillment_status' => null,
                'gateway' => 'Cash on Delivery (COD)',
                'payment_gateway_names' => ['Cash on Delivery (COD)'],
                'source_name' => 'web',
                'tags' => '',
                'phone' => $phone,
                'subtotal_price' => '650.00',
                'total_price' => '710.00',
                'total_discounts' => '0.00',
                'total_shipping_price_set' => ['shop_money' => ['amount' => '60.00', 'currency_code' => 'EGP']],
                'customer' => ['id' => $id + 1, 'first_name' => $first, 'last_name' => $last, 'phone' => $phone, 'created_at' => $at, 'updated_at' => $at, 'default_address' => $address],
                'shipping_address' => $address,
                'billing_address' => $address,
                'line_items' => [
                    ['id' => $id + 2, 'title' => 'فستان سهرة شيفون', 'variant_title' => 'أسود / M', 'name' => 'فستان سهرة شيفون - أسود / M', 'sku' => 'SIM-DRS', 'quantity' => 1, 'price' => '450.00', 'product_id' => null, 'variant_id' => null],
                    ['id' => $id + 3, 'title' => 'طرحة شيفون', 'variant_title' => 'بيج', 'name' => 'طرحة شيفون - بيج', 'sku' => 'SIM-HJB', 'quantity' => 2, 'price' => '100.00', 'product_id' => null, 'variant_id' => null],
                ],
                'shipping_lines' => [['title' => 'شحن القاهرة والجيزة', 'price' => '60.00']],
                'fulfillments' => [],
                'refunds' => [],
            ],
            'signature_valid' => true,
            'status' => 'received',
        ]);

        ProcessShopifyWebhook::dispatchSync($event->id);

        return Order::where('shopify_order_id', (string) $id)->firstOrFail();
    }

    /**
     * Broadcast failures swallowed during the most recent burst() call (see
     * the BroadcastException catch above) — 0 when broadcasting is disabled,
     * unreachable-but-not-hit, or working normally.
     */
    public function lastBurstBroadcastFailures(): int
    {
        return $this->lastBurstBroadcastFailures;
    }

    /**
     * Queued variant of customerMessage() for the web Simulator screen: the
     * webhook is processed by the queue worker (optionally after a delay) so
     * the HTTP request returns immediately, exactly like a real platform push.
     */
    /**
     * @param  array<string, mixed>  $extra  more fields for the fake event (`referral`)
     * @param  array<string, mixed>|null  $loadTest  tags the conversation's `meta.load_test` once ingested
     */
    public function queueCustomerMessage(Platform $p, string $customerKey, string $name, string $text, int $delaySeconds = 0, ?string $attachment = null, array $extra = [], ?array $loadTest = null): WebhookEvent
    {
        $event = $this->createWebhookEvent($p, 'message', [
            $this->messagePayload($p, 'sim_msg_'.Str::uuid(), $customerKey, $name, $text, now()->addSeconds($delaySeconds), $attachment, $extra),
        ], $loadTest);

        $this->dispatchEvent($event, $delaySeconds);

        return $event;
    }

    /**
     * Queued variant of comment() for the web Simulator screen.
     */
    public function queueComment(Platform $p, string $postKey, string $customerKey, string $name, string $text, bool $isAd = false): WebhookEvent
    {
        $event = $this->createWebhookEvent($p, 'comment', [[
            'type' => 'comment',
            'channel_id' => $this->ensureAccount($p)->external_id,
            'post_id' => $postKey,
            'post_caption' => 'Post '.$postKey,
            'is_ad' => $isAd,
            'comment_id' => 'sim_c_'.Str::uuid(),
            'customer_id' => $customerKey,
            'name' => $name,
            'text' => $text,
            'at' => now()->toIso8601String(),
        ]]);

        $this->dispatchEvent($event);

        return $event;
    }

    /**
     * Queues $count customer messages spread over $seconds, each from a brand-new
     * customer (unique key) so a burst looks like many people writing in at once.
     *
     * @param  array<int, Platform|string>  $platforms
     */
    public function queueBurst(int $count, int $seconds, array $platforms): int
    {
        if ($count <= 0 || $platforms === []) {
            return 0;
        }

        $platformEnums = array_values(array_map(
            fn ($p) => $p instanceof Platform ? $p : Platform::from((string) $p),
            $platforms,
        ));

        $texts = ArabicCorpus::customerOpeners();
        $names = ArabicCorpus::names();

        for ($i = 0; $i < $count; $i++) {
            $this->queueCustomerMessage(
                $platformEnums[array_rand($platformEnums)],
                'sim-web-'.Str::lower(Str::random(12)),
                $names[array_rand($names)],
                $texts[array_rand($texts)],
                $count > 1 ? intdiv($i * $seconds, $count) : 0,
            );
        }

        return $count;
    }

    /**
     * The one account all simulated traffic of a platform uses: its dedicated «تيست» channel
     * (load test, 2026-10-07). Found by its own external id, never by platform alone, so on a
     * server with the real page connected a simulated message can never attach to the real
     * account, and an agent's reply to it can never reach Meta (the account is `is_load_test`).
     */
    public function ensureAccount(Platform $p): ChannelAccount
    {
        return LoadTestChannels::account($p);
    }

    /**
     * One fake `message` event. It always names the test channel (`channel_id`), so the fake
     * adapter never falls back to the platform's first account.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function messagePayload(Platform $p, string $externalId, string $customerKey, string $name, string $text, CarbonInterface $at, ?string $attachment, array $extra): array
    {
        return [
            'type' => 'message',
            'channel_id' => $this->ensureAccount($p)->external_id,
            'id' => $externalId,
            'customer_id' => $customerKey,
            'name' => $name,
            'text' => $text,
            'at' => CarbonImmutable::instance($at)->utc()->toIso8601String(),
            'attachments' => $attachment ? [['type' => ['image' => 'image', 'voice' => 'audio', 'video' => 'video', 'file' => 'file'][$attachment], 'fixture' => $attachment]] : [],
        ] + $extra;
    }

    private function dispatchEvent(WebhookEvent $event, int $delaySeconds = 0): void
    {
        $job = ProcessWebhookEvent::dispatch($event->id)->onQueue('webhooks');

        if ($delaySeconds > 0) {
            $job->delay(now()->addSeconds($delaySeconds));
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    private function createWebhookEvent(Platform $p, string $type, array $events, ?array $loadTest = null): WebhookEvent
    {
        return WebhookEvent::create([
            'provider' => $p->value,
            'event_type' => $type,
            'dedupe_key' => (string) Str::uuid(),
            // `loadtest`: ProcessWebhookEvent reads it with the fake adapter even where the live driver
            // serves the platform — only when every event names a load-test channel.
            'payload' => ['fake' => true, 'loadtest' => true, 'events' => $events] + ($loadTest !== null ? ['load_test' => $loadTest] : []),
            'signature_valid' => true,
            'status' => 'received',
        ]);
    }
}
