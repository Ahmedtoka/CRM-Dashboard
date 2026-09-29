<?php

namespace App\Queue\Events;

use App\Models\QueueEntry;
use Illuminate\Foundation\Events\Dispatchable;

/** An inquiry / problem close stood: the customer did not come back inside the confirm window. Plain event. */
class CloseConfirmed
{
    use Dispatchable;

    public function __construct(public QueueEntry $entry) {}
}
