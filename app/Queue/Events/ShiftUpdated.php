<?php

namespace App\Queue\Events;

use App\Http\Resources\ShiftResource;
use App\Models\Shift;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** A shift opened / closed / its roster changed: the board redraws the room. */
class ShiftUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public Shift $shift) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('board')];
    }

    public function broadcastWith(): array
    {
        return (new ShiftResource($this->shift))->resolve();
    }
}
