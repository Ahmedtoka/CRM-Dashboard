<?php

namespace App\Bot;

use App\Analytics\ActivityLogger;
use App\Bot\Ai\AiResponder;
use App\Bot\Ai\MessageClassifier;
use App\Bot\Flow\TurnRunner;
use App\Bot\Flows\ConversationRouter;
use App\Bot\Grounding\BotContext;
use App\Bot\Grounding\BotContextBuilder;
use App\Bot\Knowledge\KnowledgeBase;
use App\Bot\Knowledge\SizeChart;
use App\Bot\Learning\Jobs\ReviewConversation;
use App\Enums\ActorType;
use App\Enums\AttachmentType;
use App\Enums\BotIntent;
use App\Enums\Handler;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Events\ConversationUpdated;
use App\Inbox\OutboundService;
use App\Inbox\SavedReplies\AttachmentCopier;
use App\Inbox\UserNotifier;
use App\Inbox\WindowClosedException;
use App\Inbox\WindowPolicy;
use App\Media\MediaPolicy;
use App\Media\MediaRejected;
use App\Models\BotRule;
use App\Models\BotRun;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Support\SafeBroadcast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Bot orchestration: signals → rules → AI → human (spec §4, §5.7). The bot
 * only answers questions from grounding; anything else hands over with an
 * internal summary note.
 */
class BotEngine
{
    public function __construct(
        private readonly RuleEngine $rules,
        private readonly PriceGuard $guard,
        private readonly AiResponder $ai,
        private readonly OutboundService $outbound,
        private readonly ActivityLogger $logger,
        private readonly MessageClassifier $classifier,
        private readonly BotContextBuilder $context,
        private readonly HandoverSignals $signals,
        private readonly HandoverSummary $summary,
        private readonly KnowledgeBase $knowledge,
        private readonly AttachmentCopier $copier,
        private readonly UserNotifier $notifier,
        private readonly WindowPolicy $windows,
        private readonly MediaPolicy $media,
    ) {}

    /**
     * One bot turn for a whole burst of customer messages (spec §2): the same
     * gates as handleInbound — outside hours, owner handover keywords, turn
     * limit, owner rules — checked on the joined burst text, then the
     * conversation flow. Unlike handleInbound there is no early
     * HandoverSignals handover: a phone number or "عايزة اطلب" is understood
     * as part of the turn instead of forcing a person in.
     */
    public function handleTurn(Conversation $c, Collection $burst): ?BotRun
    {
        $last = $burst->last();
        $settings = BotSetting::current();

        if ($last === null || $c->handler !== Handler::Bot || ! $settings->enabled) {
            return null;
        }

        $text = $burst->pluck('body')->map(fn ($b) => (string) $b)->filter(fn ($b) => trim($b) !== '')->implode("\n");
        $peeked = $this->rules->peek($text, 'message', $c->platform);

        if (! $this->withinWorkingHours($settings) && $peeked === null) {
            return $this->maybeSendOutsideHoursMessage($c, $last, $peeked, $settings);
        }

        if ($this->signals->matchesKeyword($text, $settings->handover_keywords)) {
            $this->handover($c, 'keyword', $text, null, $this->context->build($text, BotIntent::Other));

            return $this->recordRun($c, $last, engine: 'keyword', decision: 'handover');
        }

        if ($this->agentTurnCount($c) >= (int) $settings->max_bot_turns
            || $this->flowTurnCount($c) >= self::FLOW_TURNS_FACTOR * (int) $settings->max_bot_turns) {
            $this->handover($c, 'max_turns', $text, null, $this->context->build($text, BotIntent::Other));

            return $this->recordRun($c, $last, engine: 'limit', decision: 'handover');
        }

        // Agent rebuild: button taps, an active guided flow and greetings / "منيو" → main menu.
        if (($run = app(ConversationRouter::class)->route($c, $burst)) !== null) {
            return $run;
        }

        if ($peeked !== null) {
            $rule = $this->rules->match($text, 'message', $c->platform) ?? $peeked;

            return $this->runRule($c, $last, $rule, $text);
        }

        if (! $settings->ai_enabled) {
            $this->handover($c, 'no_rule', $text, null, $this->context->build($text, BotIntent::Other));

            return $this->recordRun($c, $last, engine: 'none', decision: 'handover');
        }

        return app(TurnRunner::class)->run($c, $burst);
    }

    public function handleInbound(Message $m): ?BotRun
    {
        $c = $m->conversation;
        $settings = BotSetting::current();

        if ($c === null || $c->handler !== Handler::Bot || ! $settings->enabled) {
            return null;
        }

        $text = (string) $m->body;

        // Purchase / contact details / size advice hand over immediately, even
        // outside working hours (spec §4.1); the closed-hours notice still goes out.
        if (($signal = $this->signals->detect($text)) !== null) {
            $intent = $signal === 'size_recommendation' ? BotIntent::SizeRecommendation : BotIntent::Purchase;
            $this->handover($c, $signal, $text, null, $this->context->build($text, $intent));

            if (! $this->withinWorkingHours($settings)) {
                $this->sendOutsideHoursMessageOnce($c, $settings);
            }

            return $this->recordRun($c, $m, engine: 'signal', decision: 'handover', intent: $intent->value);
        }

        // A read-only check: does *any* rule apply to this text at all? This
        // decides the outside-hours gate below and whether the "no rule"
        // branches later apply, without counting as a "hit" on the rule
        // (spec: a rule that never actually replies, because a handover
        // keyword or the turn limit pre-empted it, shouldn't have its hits
        // incremented).
        $peeked = $this->rules->peek($text, 'message', $c->platform);

        if (! $this->withinWorkingHours($settings) && $peeked === null) {
            return $this->maybeSendOutsideHoursMessage($c, $m, $peeked, $settings);
        }

        if ($this->signals->matchesKeyword($text, $settings->handover_keywords)) {
            $this->handover($c, 'keyword', $text, null, $this->context->build($text, BotIntent::Other));

            return $this->recordRun($c, $m, engine: 'keyword', decision: 'handover');
        }

        if ($this->botTurnCount($c) >= (int) $settings->max_bot_turns) {
            $this->handover($c, 'max_turns', $text, null, $this->context->build($text, BotIntent::Other));

            return $this->recordRun($c, $m, engine: 'limit', decision: 'handover');
        }

        if ($peeked !== null) {
            // Only now does the rule actually get used to reply, so only
            // now does it earn its `hits` increment.
            $rule = $this->rules->match($text, 'message', $c->platform) ?? $peeked;

            return $this->runRule($c, $m, $rule);
        }

        if (! $settings->ai_enabled) {
            $this->handover($c, 'no_rule', $text, null, $this->context->build($text, BotIntent::Other));

            return $this->recordRun($c, $m, engine: 'none', decision: 'handover');
        }

        return $this->runAi($c, $m, $settings);
    }

    /**
     * Priority/queue routing (spec §2.1 HandoverRouter). Callers that don't route (rules,
     * signals, handleInbound) default to priority medium, queue agents and a category equal
     * to `$reason`, so they keep working unchanged.
     *
     * @param  array{priority?:string, queue?:string, category?:string, summary_extra?:list<string>}  $routing
     */
    public function handover(Conversation $c, string $reason, ?string $customerText = null, ?BotIntent $intent = null, ?BotContext $ctx = null, array $routing = []): void
    {
        $priority = (string) ($routing['priority'] ?? 'medium');
        $queue = (string) ($routing['queue'] ?? 'agents');
        $category = (string) ($routing['category'] ?? $reason);

        $c->handler = Handler::Human;
        $c->needs_human = true;
        $c->handover_at = now();
        $c->priority_level = $priority;
        $c->queue = $queue;
        $c->handover_category = $category;
        $c->save();

        $this->outbound->sendSystem($c, 'تم تحويل المحادثة لفريق خدمة العملاء');

        $this->logger->log(ActorType::System, null, ActivityLogger::CONVERSATION_HANDOVER, null, $c, ['reason' => $reason]);

        if ($customerText !== null) {
            $this->summary->note($c, $reason, $customerText, $intent, $ctx, $routing['summary_extra'] ?? []);
        }

        $this->notifyActiveUsers($c, $reason, $priority, $queue, $category);

        SafeBroadcast::send(new ConversationUpdated($c));

        // Learning v2 §2: reviewed 10 minutes later, once the agent has answered.
        ReviewConversation::dispatchFor($c);
    }

    public function returnToBot(Conversation $c, User $u): void
    {
        $c->handler = Handler::Bot;
        $c->needs_human = false;
        $c->priority_level = null;
        $c->queue = null;
        $c->handover_category = null;
        // Task 5 ruling 6a: the bot starts fresh, keeping only the burst turn marker.
        $c->bot_state = $c->resetBotState();
        $c->save();

        $this->logger->log(ActorType::User, $u, ActivityLogger::CONVERSATION_RETURN_TO_BOT, null, $c);

        SafeBroadcast::send(new ConversationUpdated($c));
    }

    /**
     * Sends the size chart as text, then its image when one is uploaded and
     * the free-form window allows it. Once the text is queued nothing below
     * may throw: a job retry would otherwise send the text a second time.
     *
     * Final fix wave I4: the window and the platform's media rules are checked
     * against the stored image's metadata BEFORE anything is copied, and a
     * copy whose send then throws is deleted again (row and file) — so a
     * refused or failed image never leaves an orphan behind on every question.
     */
    public function sendSizeChart(Conversation $c): void
    {
        $settings = BotSetting::current();
        $chart = SizeChart::fromSettings($settings);

        if (! $this->trySendBot($c, $chart->toText()) || ! $chart->hasImage()) {
            return;
        }

        $image = null;

        try {
            $disk = (string) config('crm.media.disk', 'media');
            $path = (string) $settings->size_chart_image_path;

            if (! $this->windows->evaluate($c, SenderType::Bot)->canSendText() || ! Storage::disk($disk)->exists($path)) {
                return;
            }

            $size = (int) Storage::disk($disk)->size($path);
            $this->media->assertSendable(
                new MessageAttachment(['type' => AttachmentType::Image, 'mime' => $settings->size_chart_image_mime, 'size_bytes' => $size]),
                $c->platform,
            );

            $image = $this->copier->copy($disk, $path, AttachmentType::Image, $settings->size_chart_image_mime, 'size-chart', null, null, $size, null);
            $this->outbound->sendBotAttachment($c, $image);
        } catch (WindowClosedException|MediaRejected) {
            // Expected refusals: the text already answered the question.
            $this->discardUnsentCopy($image);
        } catch (Throwable $e) {
            $this->discardUnsentCopy($image);
            report($e);
        }
    }

    /**
     * Deletes a size-chart copy that never got linked to a message. The
     * conditional delete protects a copy a send did link before throwing
     * (e.g. a queue failure after the transaction committed).
     */
    private function discardUnsentCopy(?MessageAttachment $copy): void
    {
        if ($copy === null) {
            return;
        }

        rescue(function () use ($copy) {
            if (MessageAttachment::query()->whereKey($copy->id)->whereNull('message_id')->delete() === 1 && $copy->path) {
                Storage::disk($copy->disk)->delete($copy->path);
            }
        }, null, report: true);
    }

    /** @param  string|null  $text  the matched text (a whole burst); defaults to the message body */
    private function runRule(Conversation $c, Message $m, BotRule $rule, ?string $text = null): BotRun
    {
        $text ??= (string) $m->body;

        if ($rule->sends_size_chart) {
            $this->sendSizeChart($c);
            $this->logger->log(ActorType::Bot, null, ActivityLogger::BOT_RULE_MATCHED, $rule, $c, ['rule_id' => $rule->id, 'rule_name' => $rule->name]);

            return $this->recordRun($c, $m, engine: 'rule', decision: 'reply', ruleId: $rule->id, replyText: 'size_chart');
        }

        $replyText = ($rule->private_reply !== null && $rule->private_reply !== '')
            ? $rule->private_reply
            : ($rule->public_replies[0] ?? null);

        // Knowledge rules read the entry at runtime (never a stored copy).
        if ($rule->knowledge_key !== null && $rule->knowledge_key !== '') {
            $replyText = $this->knowledge->get($rule->knowledge_key)?->body ?? $replyText;
        }

        $decision = match ($rule->action) {
            'handover' => 'handover',
            'reply_and_handover' => 'reply_and_handover',
            default => 'reply',
        };

        $this->logger->log(ActorType::Bot, null, ActivityLogger::BOT_RULE_MATCHED, $rule, $c, ['rule_id' => $rule->id, 'rule_name' => $rule->name]);

        // A knowledge rule whose entry was switched off has nothing to say.
        if ($decision === 'reply' && ! $replyText && $rule->knowledge_key) {
            $this->handover($c, 'no_rule', $text, null, $this->context->build($text, BotIntent::Other));

            return $this->recordRun($c, $m, engine: 'rule', decision: 'handover', ruleId: $rule->id);
        }

        if (in_array($decision, ['reply', 'reply_and_handover'], true) && $replyText) {
            $this->trySendBot($c, $replyText);
        }

        if (in_array($decision, ['handover', 'reply_and_handover'], true)) {
            $this->handover($c, 'rule', $text, null, $this->context->build($text, BotIntent::Other));
        }

        return $this->recordRun($c, $m, engine: 'rule', decision: $decision, ruleId: $rule->id, replyText: $replyText);
    }

    private function runAi(Conversation $c, Message $m, BotSetting $settings): BotRun
    {
        $text = (string) $m->body;

        try {
            $cl = $this->classifier->classifyMessage($text);
        } catch (Throwable) {
            $this->handover($c, 'ai_error', $text);

            return $this->recordRun($c, $m, engine: 'ai', decision: 'handover');
        }

        $meta = [
            'engine' => 'ai',
            'intent' => $cl->intent->value,
            'confidence' => $cl->confidence,
            'model' => $cl->model !== '' ? $cl->model : null,
            'inputTokens' => $cl->inputTokens,
            'outputTokens' => $cl->outputTokens,
            'cost' => $this->tokenCost($cl->model, $cl->inputTokens, $cl->outputTokens),
            'latencyMs' => $cl->latencyMs,
        ];

        if (($reason = $cl->handoverReason((float) $settings->min_confidence)) !== null) {
            $this->handover($c, $reason, $text, $cl->intent, $this->context->build($text, $cl->intent));

            return $this->recordRun($c, $m, ...$meta, decision: 'handover');
        }

        if ($cl->intent === BotIntent::SizeChart) {
            $this->sendSizeChart($c);

            return $this->recordRun($c, $m, ...$meta, decision: 'reply', replyText: 'size_chart');
        }

        $ctx = $this->context->build($text, $cl->intent);

        if ($ctx->missingProductFor($cl->intent)) {
            $this->handover($c, 'no_product_match', $text, $cl->intent, $ctx);

            return $this->recordRun($c, $m, ...$meta, decision: 'handover');
        }

        $prompt = trim((string) $settings->system_prompt) !== '' ? (string) $settings->system_prompt : BotSetting::DEFAULT_SYSTEM_PROMPT;

        try {
            $reply = $this->ai->reply($this->buildHistory($c), $ctx->lines, $prompt);
        } catch (Throwable) {
            $this->handover($c, 'ai_error', $text, $cl->intent, $ctx);

            return $this->recordRun($c, $m, ...$meta, decision: 'handover');
        }

        $replyMeta = array_merge($meta, [
            'model' => $reply->model,
            'replyText' => $reply->text,
            'inputTokens' => $cl->inputTokens + $reply->inputTokens,
            'outputTokens' => $cl->outputTokens + $reply->outputTokens,
            'cost' => round($meta['cost'] + $this->tokenCost($reply->model, $reply->inputTokens, $reply->outputTokens), 4),
            'latencyMs' => $cl->latencyMs + $reply->latencyMs,
        ]);

        if ($reply->action !== 'reply' || trim($reply->text) === '') {
            $this->handover($c, 'ai_handover', $text, $cl->intent, $ctx);

            return $this->recordRun($c, $m, ...$replyMeta, decision: 'handover');
        }

        // Every number in the reply must come from the grounding (ruling 2).
        if (! $this->guard->isSafe($reply->text, $ctx->lines)) {
            $this->handover($c, 'ai_guard', $text, $cl->intent, $ctx);

            return $this->recordRun($c, $m, ...$replyMeta, decision: 'handover');
        }

        try {
            $this->outbound->sendBot($c, $reply->text);
        } catch (WindowClosedException) {
            $this->handover($c, 'window_closed', $text, $cl->intent, $ctx);

            return $this->recordRun($c, $m, ...$replyMeta, decision: 'handover');
        }

        $this->logger->log(ActorType::Bot, null, ActivityLogger::BOT_AI_REPLY, null, $c, ['intent' => $cl->intent->value, 'confidence' => $cl->confidence, 'model' => $reply->model]);

        return $this->recordRun($c, $m, ...$replyMeta, decision: 'reply');
    }

    /** @return bool whether the text was queued (false when the window is closed) */
    private function trySendBot(Conversation $c, string $text): bool
    {
        try {
            $this->outbound->sendBot($c, $text);

            return true;
        } catch (WindowClosedException) {
            // The reply couldn't go out (window closed); any handover that
            // follows still happens and the human will see why in the log.
            return false;
        }
    }

    private function tokenCost(string $model, int $inputTokens, int $outputTokens): float
    {
        if ($model === '') {
            return 0.0;
        }

        $prices = config('crm.anthropic.prices', []);
        $price = $prices[$model] ?? null;

        if ($price === null) {
            return 0.0;
        }

        return round(
            ($inputTokens / 1_000_000) * $price['input']
            + ($outputTokens / 1_000_000) * $price['output'],
            4
        );
    }

    private function withinWorkingHours(BotSetting $settings): bool
    {
        $hours = $settings->working_hours;

        if (empty($hours)) {
            return true;
        }

        $now = CarbonImmutable::now('Africa/Cairo');
        $days = $hours['days'] ?? range(0, 6);

        if (! in_array($now->dayOfWeek, $days, true)) {
            return false;
        }

        $current = $now->format('H:i');
        $from = $hours['from'] ?? '00:00';
        $to = $hours['to'] ?? '23:59';

        // A range like {"from":"22:00","to":"02:00"} crosses midnight: "now"
        // is inside it when it's at/after `from` OR at/before `to`, not both.
        if ($from > $to) {
            return $current >= $from || $current <= $to;
        }

        return $current >= $from && $current <= $to;
    }

    private function maybeSendOutsideHoursMessage(Conversation $c, Message $m, ?BotRule $rule, BotSetting $settings): ?BotRun
    {
        if (! $this->sendOutsideHoursMessageOnce($c, $settings)) {
            return null;
        }

        return $this->recordRun($c, $m, engine: $rule !== null ? 'rule' : 'system', decision: 'reply', ruleId: $rule?->id, replyText: $settings->outside_hours_message);
    }

    /** @return bool whether the outside-hours notice was sent now (it goes out once per conversation) */
    private function sendOutsideHoursMessageOnce(Conversation $c, BotSetting $settings): bool
    {
        $message = $settings->outside_hours_message;

        if (! $message) {
            return false;
        }

        $alreadySent = $c->messages()
            ->whereIn('sender_type', [SenderType::System->value, SenderType::Bot->value])
            ->where('body', $message)
            ->exists();

        if ($alreadySent) {
            return false;
        }

        $this->trySendBot($c, $message);

        $this->logger->log(ActorType::Bot, null, ActivityLogger::BOT_OUTSIDE_HOURS, null, $c);

        return true;
    }

    /** Guided-flow turns may run this many times max_bot_turns before a person takes over. */
    public const FLOW_TURNS_FACTOR = 3;

    /** TurnRunner decisions recorded under engine flow_engine that composed an agent answer. */
    private const AGENT_FLOW_DECISIONS = ['flow_started', 'case_exists'];

    /**
     * The turn limit of the burst pipeline (agent rebuild): agent turns since a person last wrote. Router
     * turns (engine flow_engine: menus, button taps, flow answers) are left out, since a flow sends several
     * prompts by design; a TurnRunner turn that started a flow still counts. Flow turns have their own
     * backstop, flowTurnCount() against FLOW_TURNS_FACTOR × max_bot_turns.
     */
    private function agentTurnCount(Conversation $c): int
    {
        return $this->runsSinceLastHuman($c)
            ->where(fn ($q) => $q->where('engine', '!=', 'flow_engine')->orWhereIn('decision', self::AGENT_FLOW_DECISIONS))
            ->count();
    }

    /** Guided-flow router turns since a person last wrote. */
    private function flowTurnCount(Conversation $c): int
    {
        return $this->runsSinceLastHuman($c)
            ->where('engine', 'flow_engine')
            ->whereNotIn('decision', self::AGENT_FLOW_DECISIONS)
            ->count();
    }

    private function runsSinceLastHuman(Conversation $c): Builder
    {
        $lastHumanAt = $c->messages()->where('sender_type', SenderType::User->value)->latest('id')->value('created_at');

        return BotRun::query()
            ->where('conversation_id', $c->id)
            ->when($lastHumanAt, fn ($q) => $q->where('created_at', '>', $lastHumanAt));
    }

    /**
     * Bot outbound messages sent since the last human (user) outbound
     * message in the conversation.
     */
    private function botTurnCount(Conversation $c): int
    {
        $lastHumanId = $c->messages()->where('sender_type', SenderType::User->value)->max('id');

        return $c->messages()
            ->where('sender_type', SenderType::Bot->value)
            ->when($lastHumanId, fn ($q) => $q->where('id', '>', $lastHumanId))
            ->count();
    }

    /**
     * @return array<int, array{role: 'customer'|'agent', text: string}>
     */
    private function buildHistory(Conversation $c): array
    {
        return $c->messages()
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (Message $m) => [
                'role' => $m->sender_type === SenderType::Customer ? 'customer' : 'agent',
                'text' => (string) $m->body,
            ])
            ->all();
    }

    /**
     * Notification audience/type from the routing (spec §2.1 HandoverRouter): the senior queue
     * only reaches active supervisors/admins with platform access; every other handover keeps the
     * existing "every active user with platform access" audience. A high priority sends the urgent
     * notification type so it stands out from a routine handover.
     */
    private function notifyActiveUsers(Conversation $c, string $reason, string $priority = 'medium', string $queue = 'agents', ?string $category = null): void
    {
        $platform = $c->platform instanceof Platform ? $c->platform : Platform::from((string) $c->platform);
        $type = $priority === 'high' ? 'conversation.handover_urgent' : 'conversation.handover';

        User::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $u) => $u->canAccessPlatform($platform))
            ->filter(fn (User $u) => $queue !== 'senior' || $u->isSupervisorOrAbove())
            ->each(fn (User $u) => $this->notifier->notify($u, $type, [
                'conversation_id' => $c->id,
                'reason' => $reason,
                'customer_name' => $c->customer?->name,
                'platform' => $platform->value,
                'priority_level' => $priority,
                'queue' => $queue,
                'category' => $category ?? $reason,
            ]));
    }

    private function recordRun(
        Conversation $c,
        Message $m,
        string $engine,
        string $decision,
        ?int $ruleId = null,
        ?string $model = null,
        ?string $intent = null,
        ?float $confidence = null,
        ?string $replyText = null,
        int $inputTokens = 0,
        int $outputTokens = 0,
        float $cost = 0.0,
        int $latencyMs = 0,
    ): BotRun {
        return BotRun::create([
            'conversation_id' => $c->id,
            'trigger_message' => $m->body,
            'engine' => $engine,
            'rule_id' => $ruleId,
            'model' => $model,
            'intent' => $intent,
            'confidence' => $confidence,
            'decision' => $decision,
            'reply_text' => $replyText,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_usd' => $cost,
            'latency_ms' => $latencyMs,
        ]);
    }
}
