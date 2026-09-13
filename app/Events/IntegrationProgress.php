<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Shopify initial-import progress (spec §4.1 point 3), pushed to admins on
 * `private-integrations` on every stage change and every mapped chunk.
 */
class IntegrationProgress implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public array $importState) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('integrations')];
    }

    public function broadcastWith(): array
    {
        return ['provider' => 'shopify', 'import_state' => $this->importState];
    }
}
