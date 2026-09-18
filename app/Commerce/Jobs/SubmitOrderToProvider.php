<?php

namespace App\Commerce\Jobs;

use App\Commerce\OrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Sends a locally saved `submitting` order to the store (spec §5.2 step 3-4).
 * User/auth errors fail the order inside OrderService::submit(); only
 * throttled/transport errors are rethrown so the queue retries them, and the
 * failed() hook marks the order failed once retries are exhausted.
 *
 * No retryUntil(): Laravel ignores $tries when retryUntil is defined, and the
 * plan mandates 3 attempts with 10/30/90 s backoff. Retries are duplicate-safe
 * because OrderService::submit() adopts an order an earlier attempt created
 * (stored ids, or the install-unique `crm-{install}-order-{id}` tag on the
 * store), and never runs concurrently for one order: it holds the
 * `order-submit-{id}` lock, and a busy lock releases this job back briefly.
 * $timeout stays below the `redis` connection's retry_after (90 s).
 */
final class SubmitOrderToProvider implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 90];

    /** Releases the unique lock even if a worker dies mid-job. */
    public int $uniqueFor = 900;

    public function __construct(public readonly int $orderId)
    {
        $this->onQueue('commerce');
    }

    public function uniqueId(): string
    {
        return "order-{$this->orderId}";
    }

    public function handle(OrderService $orders): void
    {
        if (! $orders->submit($this->orderId)) {
            // Another execution is submitting this order right now.
            $this->release(10);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(OrderService::class)->markSubmissionFailed($this->orderId, $exception);
    }
}
