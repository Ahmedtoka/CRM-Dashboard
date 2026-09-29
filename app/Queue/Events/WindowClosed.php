<?php

namespace App\Queue\Events;

use App\Models\QueueEntry;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A moderator window was closed (any reason in QueueEntry::CLOSE_REASONS). Plain in-process
 * event, dispatched inside the close's transaction: Part 2's ScoreKeeper awards points here.
 */
class WindowClosed
{
    use Dispatchable;

    public function __construct(public QueueEntry $entry, public string $reason) {}
}
