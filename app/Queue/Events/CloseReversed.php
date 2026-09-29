<?php

namespace App\Queue\Events;

use App\Models\QueueEntry;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The customer came back inside the confirm window of this closed entry: Part 2 reverses its
 * points. Plain in-process event, at most once per close (`queue_entries.reversed_at`).
 *
 * Listener contract: dispatched AFTER the transaction that changed the entry has committed
 * (`DB::afterCommit`), so a listener reads committed rows and may be queued (`ShouldQueue`).
 * A listener that throws is reported and swallowed: it can never roll back or fail the close,
 * the customer's inbound message, `resolve()` or `returnToBot()`. The event is not re-sent
 * after a failure and a safety net may repeat work, so listeners must be idempotent per entry
 * (Part 2 keys its score rows by a unique key per entry and event).
 */
class CloseReversed
{
    use Dispatchable;

    public function __construct(public QueueEntry $entry) {}
}
