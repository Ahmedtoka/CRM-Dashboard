<?php

namespace App\Bot\Learning\Jobs;

use App\Bot\Learning\ConversationReview;
use App\Bot\Learning\ConversationReviewer;
use App\Models\Conversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Learning v2 §2: reviews a conversation shortly after it ends — resolved by
 * an agent or handed over by the bot. Delayed 10 minutes because a handover is
 * usually followed by the agent's answer, which is exactly what the bot should
 * learn from; repeated triggers inside 15 minutes collapse into one job.
 */
class ReviewConversation implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** One retry at most; after that the nightly backfill picks it up. */
    public int $tries = 2;

    public int $backoff = 60;

    public int $timeout = 60;

    public const LOCK_SECONDS = 900;

    public function __construct(public int $conversationId)
    {
        $this->onQueue('default');
    }

    public static function lockKey(int $conversationId): string
    {
        return "learning:review:{$conversationId}";
    }

    /**
     * Queues a review for `$conversation` when it is worth one. Never throws:
     * resolving a conversation or handing it over must not fail because the
     * learning side did.
     */
    public static function dispatchFor(Conversation $conversation): void
    {
        try {
            if (app(ConversationReview::class)->skipReason($conversation) !== null) {
                return;
            }

            if (! Cache::lock(self::lockKey($conversation->id), self::LOCK_SECONDS)->get()) {
                return;
            }

            self::dispatch($conversation->id)
                ->delay(now()->addMinutes((int) config('crm.learning.review_delay_minutes', 10)));
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function handle(ConversationReview $review, ConversationReviewer $reviewer): void
    {
        $conversation = Conversation::query()->find($this->conversationId);

        if ($conversation === null) {
            return;
        }

        $review->review($conversation, $reviewer);
    }

    /** Design "Error handling": log it and store nothing. */
    public function failed(Throwable $e): void
    {
        Log::warning("learning: review of conversation {$this->conversationId} failed: {$e->getMessage()}");
    }
}
