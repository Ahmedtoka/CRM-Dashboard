<?php

namespace App\Comments;

use App\Analytics\ActivityLogger;
use App\Analytics\AttributionRecorder;
use App\Channels\ChannelRegistry;
use App\Enums\ActorType;
use App\Enums\CommentStatus;
use App\Enums\ConversationSource;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Events\CommentUpdated;
use App\Events\ConversationUpdated;
use App\Events\MessageCreated;
use App\Inbox\InboxIngestor;
use App\Models\Comment;
use App\Models\Conversation;
use App\Models\CustomerIdentity;
use App\Models\User;
use App\Support\SafeBroadcast;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Manual/bot comment actions: public reply, hide, and the once-per-comment
 * private reply that opens (or reuses) a conversation (spec §5.5).
 */
class CommentActions
{
    public function __construct(
        private readonly ChannelRegistry $registry,
        private readonly InboxIngestor $inbox,
        private readonly ActivityLogger $logger,
        private readonly AttributionRecorder $attribution,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws CommentActionFailedException
     */
    public function reply(Comment $c, string $text, ?User $by): Comment
    {
        $post = $c->post;
        $this->authorize($post->platform, $by);

        $result = $this->registry->adapter($post->platform)->replyToComment($post->channelAccount, $c->external_id, $text);

        if (! $result->success) {
            throw new CommentActionFailedException((string) $result->error);
        }

        $c->forceFill([
            'status' => CommentStatus::Replied,
            'public_reply' => $text,
            'public_replied_at' => now(),
            'replied_by_type' => $by !== null ? ActorType::User : ActorType::Bot,
            'replied_by_id' => $by?->id,
        ])->save();

        $this->logger->log(
            $by !== null ? ActorType::User : ActorType::Bot,
            $by,
            ActivityLogger::COMMENT_REPLIED,
            $c,
            $c->conversation,
            ['comment_id' => $c->id, 'text' => $text],
        );

        SafeBroadcast::send(new CommentUpdated($c));

        return $c;
    }

    /**
     * @throws AuthorizationException
     * @throws CommentActionFailedException
     */
    public function hide(Comment $c, ?User $by): Comment
    {
        $post = $c->post;
        $this->authorize($post->platform, $by);

        $result = $this->registry->adapter($post->platform)->hideComment($post->channelAccount, $c->external_id);

        if (! $result->success) {
            throw new CommentActionFailedException((string) $result->error);
        }

        $c->forceFill(['status' => CommentStatus::Hidden])->save();

        $this->logger->log(
            $by !== null ? ActorType::User : ActorType::Bot,
            $by,
            ActivityLogger::COMMENT_HIDDEN,
            $c,
            $c->conversation,
            ['comment_id' => $c->id],
        );

        SafeBroadcast::send(new CommentUpdated($c));

        return $c;
    }

    /**
     * @throws AuthorizationException
     * @throws PrivateReplyNotAllowedException
     * @throws CommentActionFailedException
     */
    public function privateReply(Comment $c, string $text, ?User $by): Conversation
    {
        $post = $c->post;
        $this->authorize($post->platform, $by);

        $adapter = $this->registry->adapter($post->platform);

        if (! $adapter->capabilities()->privateReply) {
            throw new PrivateReplyNotAllowedException($post->platform->label().' does not support private replies.');
        }

        $days = (int) config('crm.private_reply_days', 7);

        if ($c->created_at !== null && $c->created_at->diffInDays(now()) > $days) {
            throw new PrivateReplyNotAllowedException("The private reply window ({$days} days) has expired.");
        }

        $identity = CustomerIdentity::where('customer_id', $c->customer_id)
            ->where('platform', $post->platform)
            ->firstOrFail();

        // Atomically claim the once-per-comment private reply slot before
        // calling the adapter, so two concurrent callers (e.g. a human
        // clicking "send" while the bot job runs) can't both send one. An
        // in-memory `$c->private_reply_sent_at !== null` check would be
        // racy: it can pass on a stale model instance even though another
        // request already claimed the row.
        $claimedAt = now();
        $claimed = Comment::whereKey($c->id)->whereNull('private_reply_sent_at')->update(['private_reply_sent_at' => $claimedAt]);

        if ($claimed === 0) {
            throw new PrivateReplyNotAllowedException('A private reply has already been sent for this comment.');
        }

        $c->setAttribute('private_reply_sent_at', $claimedAt);

        $account = $post->channelAccount;

        try {
            $result = $adapter->sendPrivateReply($account, $c->external_id, $text);
        } catch (Throwable $e) {
            $this->resetClaim($c);

            throw new CommentActionFailedException($e->getMessage(), previous: $e);
        }

        if (! $result->success) {
            $this->resetClaim($c);

            throw new CommentActionFailedException((string) $result->error);
        }

        $source = $post->is_ad ? ConversationSource::Ad : ConversationSource::Comment;

        // The external message is already sent at this point: any failure
        // from here on must NOT roll back the claim (that would let a second
        // caller send a duplicate private reply), so it's kept and the
        // failure is only logged/rethrown for a human/ops to reconcile.
        try {
            [$conversation, $message] = DB::transaction(function () use ($c, $account, $post, $source, $identity, $text, $by, $result) {
                $conversation = $this->inbox->openConversationFor($identity, $account, $source, $c->id);

                $message = $conversation->messages()->create([
                    'platform' => $post->platform,
                    'direction' => MessageDirection::Out,
                    'sender_type' => $by !== null ? SenderType::User : SenderType::Bot,
                    'user_id' => $by?->id,
                    'body' => $text,
                    'status' => MessageStatus::Sent,
                    'external_id' => $result->externalId,
                    'sent_at' => now(),
                ]);
                $message->setRelation('conversation', $conversation);
                if ($by !== null) {
                    $message->setRelation('user', $by);
                }

                // The window opens only when the customer replies; it does
                // not open just because we spoke first.
                $conversation->forceFill(['last_message_at' => now()])->save();

                $c->forceFill(['conversation_id' => $conversation->id])->save();

                $this->attribution->recordOutbound($message);

                $this->logger->log(
                    $by !== null ? ActorType::User : ActorType::Bot,
                    $by,
                    ActivityLogger::COMMENT_PRIVATE_REPLY,
                    $c,
                    $conversation,
                    ['comment_id' => $c->id],
                );

                return [$conversation, $message];
            });
        } catch (Throwable $e) {
            Log::error('comment.private_reply.persist_failed', [
                'comment_id' => $c->id,
                'external_id' => $result->externalId,
                'error' => $e->getMessage(),
            ]);

            try {
                $this->logger->log(
                    ActorType::System,
                    null,
                    ActivityLogger::COMMENT_PRIVATE_REPLY,
                    $c,
                    null,
                    [
                        'persist_failed' => true,
                        'external_id' => $result->externalId,
                        'error' => $e->getMessage(),
                    ],
                );
            } catch (Throwable) {
                // Best-effort: never let the audit trail hide the real failure.
            }

            throw $e;
        }

        SafeBroadcast::send(new MessageCreated($message));
        SafeBroadcast::send(new ConversationUpdated($conversation));
        SafeBroadcast::send(new CommentUpdated($c));

        return $conversation;
    }

    private function resetClaim(Comment $c): void
    {
        Comment::whereKey($c->id)->update(['private_reply_sent_at' => null]);
        $c->setAttribute('private_reply_sent_at', null);
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(Platform $platform, ?User $by): void
    {
        if ($by !== null && ! $by->canAccessPlatform($platform)) {
            throw new AuthorizationException("You are not allowed to act on comments for {$platform->label()}.");
        }
    }
}
