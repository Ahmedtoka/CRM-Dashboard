<?php

namespace App\Queue;

use App\Models\QueueEntry;

/**
 * A moderator window from delivery to close. Stub for now: Task 7 fills it
 * (silence warn / auto close, manual close, reopen, escalation, transfer).
 */
class WindowLifecycle
{
    /** Task 7: the customer came back inside the confirm window — undo the close's points. */
    public function reverseClose(QueueEntry $e): void {}

    /** Task 7: close this window and re-queue the customer to someone else. */
    public function transferAway(QueueEntry $e, string $why): void {}
}
