<?php

namespace App\Queue\Events;

use App\Http\Resources\ShiftMemberResource;
use App\Models\ShiftMember;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** A moderator's desk changed (status, break, windows, counters). */
class QueueMemberUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public ShiftMember $member) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('board')];
    }

    public function broadcastWith(): array
    {
        return (new ShiftMemberResource($this->member))->resolve();
    }
}
