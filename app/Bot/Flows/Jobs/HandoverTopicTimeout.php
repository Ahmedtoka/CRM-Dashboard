<?php

namespace App\Bot\Flows\Jobs;

use App\Bot\Flows\HumanHandover;
use App\Models\Conversation;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * «كلم موظف» (2026-09-19): she did not say what she needs within
 * HumanHandover::TOPIC_WAIT_SECONDS, so she is handed over anyway. Only the
 * question it was queued for counts (asked_at), and never before it is due
 * (a sync queue runs the job at once).
 */
class HandoverTopicTimeout implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $conversationId, public readonly string $askedAt)
    {
        $this->onQueue('bot');
    }

    public function handle(HumanHandover $handover): void
    {
        try {
            $due = CarbonImmutable::parse($this->askedAt)->addSeconds(HumanHandover::TOPIC_WAIT_SECONDS);
        } catch (Throwable) {
            return;
        }

        if ($due->isFuture()) {
            return;
        }

        $c = Conversation::find($this->conversationId);

        if ($c !== null) {
            $handover->timeout($c, $this->askedAt);
        }
    }
}
