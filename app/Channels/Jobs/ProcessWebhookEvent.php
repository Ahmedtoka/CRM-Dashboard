<?php

namespace App\Channels\Jobs;

use App\Channels\Adapters\FakeChannelAdapter;
use App\Channels\ChannelRegistry;
use App\Channels\Data\DeliveryReceiptData;
use App\Channels\Data\InboundCommentData;
use App\Channels\Data\InboundMessageData;
use App\Enums\Platform;
use App\Models\WebhookEvent;
use App\Simulator\LoadTest\LoadTest;
use App\Simulator\LoadTest\LoadTestChannels;
use App\Simulator\LoadTest\LoadTestTagger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class ProcessWebhookEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [5, 15, 60, 120];

    public function __construct(public readonly int $webhookEventId) {}

    /**
     * Note: InboxIngestor (Task 3) and CommentIngestor (Task 5) do not exist
     * yet, so they are resolved from the container at runtime rather than
     * via constructor/method injection, which would fail reflection when
     * Laravel builds the job's dependencies.
     */
    public function handle(ChannelRegistry $registry): void
    {
        $event = WebhookEvent::find($this->webhookEventId);

        if (! $event) {
            return;
        }

        $event->increment('attempts');

        try {
            $platform = Platform::from($event->provider);
            $loadTest = self::isLoadTest($platform, (array) ($event->payload ?? []));

            // A load-test line whose run was stopped (or whose gate was turned off) while it waited
            // on the queue: dropped, so `crm:load-test stop` really stops the customers.
            if ($loadTest && isset($event->payload['load_test']) && ! LoadTest::runIsLive($event->payload['load_test']['run'] ?? null)) {
                $event->update(['status' => 'processed', 'processed_at' => now(), 'error' => 'load_test_stopped']);

                return;
            }
            $adapter = $loadTest ? new FakeChannelAdapter($platform) : $registry->adapter($platform);
            $normalized = $adapter->normalize($event->payload ?? []);

            $inboxIngestor = app('App\\Inbox\\InboxIngestor');
            $commentIngestor = app('App\\Comments\\CommentIngestor');

            foreach ($normalized as $dto) {
                match (true) {
                    $dto instanceof InboundMessageData => $loadTest
                        ? LoadTestTagger::tag($inboxIngestor->ingestMessage($dto, $event), $event->payload['load_test'] ?? null)
                        : $inboxIngestor->ingestMessage($dto, $event),
                    $dto instanceof DeliveryReceiptData => $inboxIngestor->ingestReceipt($dto),
                    $dto instanceof InboundCommentData => $commentIngestor->ingest($dto),
                    default => null,
                };
            }

            $event->update([
                'status' => 'processed',
                'processed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // The error column has room for a real diagnostic message (up to
            // 2,000 chars), but a raw exception message (e.g. a broadcast
            // adapter's cURL error) is unbounded — truncate defensively so
            // this update itself can never throw and mask the real failure.
            $event->update([
                'status' => 'failed',
                'error' => Str::limit($e->getMessage(), 2000),
            ]);

            throw $e;
        }
    }

    /**
     * A simulated event of the production load test (App\Simulator\Simulator, 2026-10-07): read
     * with the fake adapter even where the live driver serves the platform. Only when the payload
     * says so AND every event names a load-test channel — a real Meta payload carries neither,
     * and a forged one naming the real page is left to the platform's own adapter (which finds
     * nothing in it), so a simulated message can never land on a real account.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function isLoadTest(Platform $platform, array $payload): bool
    {
        if (($payload['loadtest'] ?? false) !== true || ! is_array($payload['events'] ?? null) || $payload['events'] === []) {
            return false;
        }

        $channels = array_map(fn ($e) => is_array($e) ? (string) ($e['channel_id'] ?? '') : '', $payload['events']);

        return LoadTestChannels::allTest($platform, $channels);
    }
}
