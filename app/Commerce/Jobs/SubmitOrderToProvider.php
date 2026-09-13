<?php

namespace App\Commerce\Jobs;

use App\Commerce\OrderService;
use DateTimeInterface;
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
 */
final class SubmitOrderToProvider implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 90];

    /** Releases the unique lock even if a worker dies mid-job. */
    public int $uniqueFor = 900;

    public function __construct(public readonly int $orderId)
    {
        $this->onQueue('commerce');
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    public function uniqueId(): string
    {
        return "order-{$this->orderId}";
    }

    public function handle(OrderService $orders): void
    {
        $orders->submit($this->orderId);
    }

    public function failed(?Throwable $exception): void
    {
        app(OrderService::class)->markSubmissionFailed($this->orderId, $exception);
    }
}
