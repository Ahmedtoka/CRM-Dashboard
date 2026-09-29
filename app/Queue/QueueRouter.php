<?php

namespace App\Queue;

use Illuminate\Support\Facades\DB;

/**
 * Hands waiting entries to free moderator windows. Stub for now: Task 6 fills `run()`.
 */
class QueueRouter
{
    /** Routes once the surrounding transaction (if any) has committed. */
    public function runAfterCommit(string $trigger): void
    {
        DB::afterCommit(fn () => $this->run($trigger));
    }

    /** Task 6: assign waiting entries to members with a free window and log a QueueDecision. */
    public function run(string $trigger): void {}
}
