<?php

namespace App\Simulator\LoadTest;

use App\Queue\QueueRouter;

/**
 * Stands in for the queue router while `seed-yesterday` writes the backlog under yesterday's clock
 * (review round 1): the backlog is only enqueued there. Nobody is called, no real customer's ticket
 * is touched, no notification goes out; the real router serves the backlog once a shift opens.
 */
class BacklogRouter extends QueueRouter
{
    public function run(string $trigger): int
    {
        return 0;
    }
}
