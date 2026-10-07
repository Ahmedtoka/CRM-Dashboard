<?php

namespace App\Simulator\LoadTest\Jobs;

use App\Simulator\LoadTest\FollowUps;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A simulated customer's next line in a load-test chat (FollowUps), 60-120 s after an agent
 * replied. Re-checks everything when it runs: the gate, the run, the chat still open.
 */
class SendLoadTestFollowUp implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $conversationId, public readonly int $step)
    {
        $this->onQueue('webhooks');
    }

    public function handle(FollowUps $followUps): void
    {
        $followUps->send($this->conversationId, $this->step);
    }
}
