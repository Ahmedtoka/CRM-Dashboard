<?php

namespace App\Channels\Http;

use App\Channels\ChannelHealth;
use App\Channels\ChannelRegistry;
use App\Channels\Jobs\ProcessWebhookEvent;
use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends Controller
{
    public function __construct(
        private readonly ChannelRegistry $registry,
        private readonly ChannelHealth $health,
    ) {}

    public function handle(Request $request, string $platform): Response
    {
        $platformEnum = Platform::from($platform);
        $adapter = $this->registry->adapter($platformEnum);

        if ($request->isMethod('get')) {
            return $adapter->handshake($request) ?? response('Forbidden', 403);
        }

        if (! $adapter->verifySignature($request)) {
            return response('Forbidden', 403);
        }

        // Settings → Channels shows when each platform last reached us.
        $this->health->recordWebhook($platformEnum);

        $raw = $request->getContent();
        $dedupeKey = sha1($raw);
        // Captured before storing (spec §11.3): the inbound-latency window starts here.
        // received_at is for display only (its DATETIME(0) column truncates to whole
        // seconds); received_at_ms is the real source of truth LatencyRecorder reads.
        $receivedAt = CarbonImmutable::now();
        $receivedAtMs = (int) floor(microtime(true) * 1000);

        $event = WebhookEvent::firstOrCreate(
            ['provider' => $platform, 'dedupe_key' => $dedupeKey],
            [
                'payload' => json_decode($raw, true) ?? [],
                'signature_valid' => true,
                'status' => 'received',
                'received_at' => $receivedAt,
                'received_at_ms' => $receivedAtMs,
            ],
        );

        if (! $event->wasRecentlyCreated) {
            $event->update(['status' => 'duplicate']);

            return response('EVENT_RECEIVED', 200);
        }

        ProcessWebhookEvent::dispatch($event->id)->onQueue('webhooks');

        return response('EVENT_RECEIVED', 200);
    }
}
