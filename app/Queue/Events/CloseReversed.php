<?php

namespace App\Queue\Events;

use App\Models\QueueEntry;
use Illuminate\Foundation\Events\Dispatchable;

/** The customer came back inside the confirm window of this closed entry: Part 2 reverses its points. Plain event. */
class CloseReversed
{
    use Dispatchable;

    public function __construct(public QueueEntry $entry) {}
}
