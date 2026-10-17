<?php

namespace App\Comments\Jobs;

use App\Comments\Commands\PollTikTokAdCommentsCommand;
use App\Comments\TikTok\TikTokAdCommentPoller;
use App\Models\AdAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** One TikTok ad account's comment poll (E1). One at a time per account; a failed run simply waits for the next tick. */
class PollTikTokAdComments implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(public int $adAccountId) {}

    public function uniqueId(): string
    {
        return (string) $this->adAccountId;
    }

    public function handle(TikTokAdCommentPoller $poller): void
    {
        // Re-checked here: a job queued before the driver or the switch was turned off must not call TikTok.
        if (! PollTikTokAdCommentsCommand::enabled()) {
            return;
        }
        $account = AdAccount::with('connection')->find($this->adAccountId);
        if ($account !== null) {
            $poller->poll($account);
        }
    }
}
