<?php

namespace App\Queue\Events;

use App\Models\QueueEntry;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An inquiry / problem close stood: the customer did not come back inside the confirm window.
 * Plain in-process event. A close is confirmed or reversed, never both.
 *
 * Listener contract: dispatched AFTER the transaction that changed the entry has committed
 * (`DB::afterCommit`), so a listener reads committed rows and may be queued (`ShouldQueue`).
 * A listener that throws is reported and swallowed: it can never roll back or fail the close,
 * the customer's inbound message, `resolve()` or `returnToBot()`. The event is not re-sent
 * after a failure and a safety net may repeat work, so listeners must be idempotent per entry
 * (Part 2 keys its score rows by a unique key per entry and event).
 */
class CloseConfirmed
{
    use Dispatchable;

    public function __construct(public QueueEntry $entry) {}
}
