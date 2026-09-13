<?php

namespace App\Comments;

use App\Channels\ChannelRegistry;
use App\Channels\Data\InboundCommentData;
use App\Comments\Jobs\RunCommentBot;
use App\Enums\CommentStatus;
use App\Events\CommentUpdated;
use App\Inbox\CustomerResolver;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Comment;
use App\Models\Post;
use App\Support\SafeBroadcast;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Ingests an inbound comment webhook event: upserts the Post, resolves the
 * customer identity, inserts the Comment (unique external id), broadcasts
 * it, and schedules the comment bot (spec §5.5 step 1).
 */
class CommentIngestor
{
    public function __construct(
        private readonly CustomerResolver $resolver,
        private readonly ChannelRegistry $registry,
    ) {}

    /**
     * @return Comment|null null when the external comment id was already ingested
     */
    public function ingest(InboundCommentData $d): ?Comment
    {
        if ($this->isDuplicate($d)) {
            return null;
        }

        $account = ChannelAccount::where('platform', $d->platform)
            ->where('external_id', $d->channelExternalId)
            ->first() ?? $this->registry->account($d->platform);

        $post = Post::firstOrCreate(
            ['platform' => $d->platform, 'external_id' => $d->postExternalId],
            [
                'channel_account_id' => $account->id,
                'caption' => $d->postCaption,
                'permalink' => $d->postPermalink,
                'is_ad' => $d->isAd,
            ],
        );

        $identity = $this->resolver->resolve($d->platform, $d->customerExternalId, $d->customerName);

        try {
            $comment = Comment::create([
                'post_id' => $post->id,
                'customer_id' => $identity->customer_id,
                'external_id' => $d->commentExternalId,
                'parent_external_id' => $d->parentExternalId,
                'body' => $d->body,
                'status' => CommentStatus::New,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null; // concurrent duplicate of the same external comment id
        }

        // The comment's age (used for the 7-day private reply window) is counted
        // from when the platform says it occurred, not from ingestion time.
        $comment->forceFill(['created_at' => $d->occurredAt])->save();
        $comment->setRelation('post', $post);

        // Schedule the comment bot before broadcasting (broadcasts are best-effort).
        $this->maybeRunBot($comment);

        SafeBroadcast::send(new CommentUpdated($comment));

        return $comment;
    }

    private function isDuplicate(InboundCommentData $d): bool
    {
        return Comment::where('external_id', $d->commentExternalId)->exists();
    }

    private function maybeRunBot(Comment $comment): void
    {
        $settings = BotSetting::current();

        if (! $settings->enabled) {
            return;
        }

        [$configMin, $configMax] = config('crm.comment_bot_delay', [5, 30]);

        $min = (int) ($settings->comment_reply_delay_min ?? $configMin);
        $max = (int) ($settings->comment_reply_delay_max ?? $configMax);

        if ($max < $min) {
            [$min, $max] = [$max, $min];
        }

        $delay = random_int($min, $max);

        RunCommentBot::dispatch($comment->id)->delay(now()->addSeconds($delay))->onQueue('bot');
    }
}
