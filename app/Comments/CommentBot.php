<?php

namespace App\Comments;

use App\Analytics\ActivityLogger;
use App\Bot\Ai\AiResponder;
use App\Bot\BotEngine;
use App\Bot\CatalogSearch;
use App\Bot\PriceGuard;
use App\Bot\RuleEngine;
use App\Channels\ChannelRegistry;
use App\Enums\ActorType;
use App\Enums\CommentIntent;
use App\Enums\CommentStatus;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Events\CommentUpdated;
use App\Events\UserNotified;
use App\Models\BotRule;
use App\Models\BotRun;
use App\Models\BotSetting;
use App\Models\Comment;
use App\Models\Conversation;
use App\Models\User;
use App\Support\SafeBroadcast;
use Throwable;

/**
 * Comment bot: rule match, else AI classification, per spec §5.5 step 2.
 */
class CommentBot
{
    /** Public text used when a private reply will follow (platform supports it). */
    private const GENERIC_PUBLIC_REPLY = 'ردينا عليك في الخاص 💌';

    /**
     * Public text used when the platform has no private-reply capability, so
     * we must not promise (or imply) that one is coming.
     */
    private const NO_PRIVATE_CAPABILITY_PUBLIC_REPLY = 'ابعتلنا على الخاص أو الواتساب للتفاصيل 💬';

    private const FALLBACK_PRIVATE_REPLY = 'أهلاً! ابعتلنا تحب تعرف إيه عن المنتج';

    public function __construct(
        private readonly RuleEngine $rules,
        private readonly CatalogSearch $catalog,
        private readonly AiResponder $ai,
        private readonly PriceGuard $guard,
        private readonly ChannelRegistry $registry,
        private readonly CommentActions $actions,
        private readonly ActivityLogger $logger,
        private readonly BotEngine $botEngine,
    ) {}

    public function handle(Comment $c): ?BotRun
    {
        $settings = BotSetting::current();

        if (! $settings->enabled) {
            return null;
        }

        // The bot runs after a random delay: act only on a comment nobody has touched
        // since it arrived (a human may have replied/hidden it, or an earlier run already
        // posted the public reply). Always decide on the current row, not the job's copy.
        $c->refresh();

        if ($c->status !== CommentStatus::New || $c->public_replied_at !== null) {
            return null;
        }

        $platform = $c->post->platform;
        $rule = $this->rules->match((string) $c->body, 'comment', $platform);

        if ($rule !== null) {
            return $this->handleRule($c, $rule, $platform);
        }

        if (! $settings->ai_enabled) {
            return $this->recordRun($c, engine: 'none', decision: 'ignored');
        }

        return $this->handleAi($c, $settings, $platform);
    }

    private function handleRule(Comment $c, BotRule $rule, Platform $platform): BotRun
    {
        if ($rule->action === 'hide') {
            $this->actions->hide($c, null);

            return $this->recordRun($c, engine: 'rule', decision: 'hide', ruleId: $rule->id);
        }

        $capabilities = $this->registry->adapter($platform)->capabilities();

        // The public reply must never leak the private_reply text: when the
        // rule has no public_replies of its own, fall back to a generic line
        // that reflects whether a private reply can actually follow.
        $publicReplies = $rule->public_replies ?: [];
        $text = $publicReplies !== []
            ? $publicReplies[array_rand($publicReplies)]
            : ($capabilities->privateReply ? self::GENERIC_PUBLIC_REPLY : self::NO_PRIVATE_CAPABILITY_PUBLIC_REPLY);

        $this->actions->reply($c, $text, null);

        $conversation = null;

        if ($rule->private_reply && $capabilities->privateReply) {
            $conversation = $this->privateReplyIfUnsent($c, (string) $rule->private_reply);
        }

        $isHandover = $rule->action === 'reply_and_handover';

        if ($isHandover && $conversation instanceof Conversation) {
            $this->botEngine->handover($conversation, 'rule');
        }

        return $this->recordRun(
            $c,
            engine: 'rule',
            decision: $isHandover ? 'reply_and_handover' : 'reply',
            ruleId: $rule->id,
            replyText: $text,
        );
    }

    private function handleAi(Comment $c, BotSetting $settings, Platform $platform): BotRun
    {
        try {
            $classification = $this->ai->classify((string) $c->body);
        } catch (Throwable) {
            return $this->recordRun($c, engine: 'ai', decision: 'ignored');
        }

        $intent = $classification->intent;
        $confidence = $classification->confidence;

        $c->forceFill(['intent' => $intent])->save();
        SafeBroadcast::send(new CommentUpdated($c));

        return match ($intent) {
            CommentIntent::Spam => $this->handleSpam($c, $intent, $confidence),
            CommentIntent::Buy, CommentIntent::Question => $this->handleBuyOrQuestion($c, $intent, $confidence, $settings, $platform),
            CommentIntent::Complaint => $this->handleComplaint($c, $intent, $confidence),
            default => $this->recordRun($c, engine: 'ai', decision: 'ignored', intent: $intent->value, confidence: $confidence),
        };
    }

    private function handleSpam(Comment $c, CommentIntent $intent, float $confidence): BotRun
    {
        $this->actions->hide($c, null);

        return $this->recordRun($c, engine: 'ai', decision: 'hide', intent: $intent->value, confidence: $confidence);
    }

    private function handleBuyOrQuestion(Comment $c, CommentIntent $intent, float $confidence, BotSetting $settings, Platform $platform): BotRun
    {
        $capabilities = $this->registry->adapter($platform)->capabilities();

        // Don't claim "check your inbox" on a platform where no private
        // reply will actually follow (e.g. TikTok).
        $this->actions->reply($c, $capabilities->privateReply ? self::GENERIC_PUBLIC_REPLY : self::NO_PRIVATE_CAPABILITY_PUBLIC_REPLY, null);

        $replyText = null;

        if ($capabilities->privateReply) {
            $replyText = self::FALLBACK_PRIVATE_REPLY;
            $catalogLines = $this->catalog->linesFor((string) $c->body);
            $guardTripped = false;

            try {
                $aiReply = $this->ai->reply([], $catalogLines, (string) ($settings->system_prompt ?? ''));

                if ($aiReply->action !== 'reply' || trim($aiReply->text) === '') {
                    $guardTripped = true;
                } elseif (! $this->guard->isSafe($aiReply->text, $catalogLines)) {
                    $guardTripped = true;
                } else {
                    $replyText = $aiReply->text;
                }
            } catch (Throwable) {
                $guardTripped = true;
            }

            if ($guardTripped) {
                // The AI either declined or proposed an unverifiable price;
                // fall back to the safe generic text and get a human to look.
                // Distinct from a complaint: this is an AI guardrail trip,
                // not a customer complaint, so it gets its own type/reason.
                $this->notifySupervisors($c, 'comment.ai_guard');

                $this->logger->log(
                    ActorType::Bot,
                    null,
                    ActivityLogger::COMMENT_FLAGGED,
                    $c,
                    $c->conversation,
                    ['reason' => 'ai_guard', 'intent' => $intent->value],
                );
            }

            $this->privateReplyIfUnsent($c, $replyText);
        }

        return $this->recordRun($c, engine: 'ai', decision: 'reply', intent: $intent->value, confidence: $confidence, replyText: $replyText);
    }

    /**
     * Re-checks the current row right before the private reply: a human may have sent
     * one while the public reply was going out. Returns the comment's conversation
     * either way (null when none exists).
     */
    private function privateReplyIfUnsent(Comment $c, string $text): ?Conversation
    {
        $fresh = $c->fresh();

        if ($fresh === null) {
            return null;
        }

        if ($fresh->private_reply_sent_at !== null) {
            return $fresh->conversation;
        }

        try {
            return $this->actions->privateReply($fresh, $text, null);
        } catch (PrivateReplyNotAllowedException) {
            // Lost the atomic claim to a concurrent sender: nothing more to do.
            return $fresh->fresh()?->conversation;
        }
    }

    private function handleComplaint(Comment $c, CommentIntent $intent, float $confidence): BotRun
    {
        $this->notifySupervisors($c, 'comment.complaint');

        $this->logger->log(
            ActorType::Bot,
            null,
            ActivityLogger::COMMENT_FLAGGED,
            $c,
            $c->conversation,
            ['reason' => 'complaint', 'intent' => $intent->value],
        );

        return $this->recordRun($c, engine: 'ai', decision: 'flagged', intent: $intent->value, confidence: $confidence);
    }

    private function notifySupervisors(Comment $c, string $type): void
    {
        User::query()
            ->where('is_active', true)
            ->whereIn('role', [UserRole::Supervisor->value, UserRole::Admin->value])
            ->get()
            ->each(fn (User $u) => SafeBroadcast::send(new UserNotified($u->id, $type, [
                'comment_id' => $c->id,
                'post_id' => $c->post_id,
            ])));
    }

    private function recordRun(
        Comment $c,
        string $engine,
        string $decision,
        ?int $ruleId = null,
        ?string $intent = null,
        ?float $confidence = null,
        ?string $replyText = null,
    ): BotRun {
        return BotRun::create([
            'comment_id' => $c->id,
            'trigger_message' => $c->body,
            'engine' => $engine,
            'rule_id' => $ruleId,
            'intent' => $intent,
            'confidence' => $confidence,
            'decision' => $decision,
            'reply_text' => $replyText,
        ]);
    }
}
