<?php

namespace App\Queue\Events;

use App\Models\QueueDecision;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** One router pass finished: its decision lines for the board's log. */
class RouterDecided implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public QueueDecision $decision) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('board')];
    }

    public function broadcastWith(): array
    {
        $d = $this->decision;

        return [
            'id' => $d->id,
            'trigger' => $d->trigger,
            'lines' => $d->lines ?? [],
            'at' => $d->created_at?->toIso8601String(),
        ];
    }
}
