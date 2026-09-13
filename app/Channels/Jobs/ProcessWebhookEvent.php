<?php

namespace App\Channels\Jobs;

use App\Channels\ChannelRegistry;
use App\Channels\Data\DeliveryReceiptData;
use App\Channels\Data\InboundCommentData;
use App\Channels\Data\InboundMessageData;
use App\Enums\Platform;
use App\Models\WebhookEvent;
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
            $adapter = $registry->adapter($platform);
            $normalized = $adapter->normalize($event->payload ?? []);

            $inboxIngestor = app('App\\Inbox\\InboxIngestor');
            $commentIngestor = app('App\\Comments\\CommentIngestor');

            foreach ($normalized as $dto) {
                match (true) {
                    $dto instanceof InboundMessageData => $inboxIngestor->ingestMessage($dto, $event),
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
}
