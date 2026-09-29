<?php

namespace App\Queue\Events;

use App\Http\Resources\ShiftMemberResource;
use App\Models\ShiftMember;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A moderator's desk changed (status, break, windows, counters, joined or left the shift): on the
 * board, and on her own `user.{id}` channel so her inbox (which does not join the board) shows or
 * drops her strip without a reload.
 */
class QueueMemberUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public ShiftMember $member) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('board')];

        if ($this->member->user_id !== null) {
            $channels[] = new PrivateChannel('user.'.$this->member->user_id);
        }

        return $channels;
    }

    public function broadcastWith(): array
    {
        return (new ShiftMemberResource($this->member))->resolve();
    }
}
