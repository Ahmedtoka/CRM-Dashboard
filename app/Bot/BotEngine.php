<?php

namespace App\Bot;

use App\Analytics\ActivityLogger;
use App\Bot\Ai\AiResponder;
use App\Enums\ActorType;
use App\Enums\CommentIntent;
use App\Enums\Handler;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Events\ConversationUpdated;
use App\Events\UserNotified;
use App\Inbox\OutboundService;
use App\Inbox\WindowClosedException;
use App\Models\BotRule;
use App\Models\BotRun;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\SafeBroadcast;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Bot orchestration: rules → AI → human, per spec §5.7.
 */
class BotEngine
{
    public function __construct(
        private readonly ArabicNormalizer $normalizer,
        private readonly RuleEngine $rules,
        private readonly CatalogSearch $catalog,
        private readonly PriceGuard $guard,
        private readonly AiResponder $ai,
        private readonly OutboundService $outbound,
        private readonly ActivityLogger $logger,
    ) {}

    public function handleInbound(Message $m): ?BotRun
    {
        $c = $m->conversation;
        $settings = BotSetting::current();

        if ($c === null || $c->handler !== Handler::Bot || ! $settings->enabled) {
            return null;
        }

        $text = (string) $m->body;
        $normalized = $this->normalizer->normalize($text);

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

        if ($this->matchesHandoverKeyword($normalized, $settings)) {
            $this->handover($c, 'keyword');

            return $this->recordRun($c, $m, engine: 'keyword', decision: 'handover');
        }

        if ($this->botTurnCount($c) >= (int) $settings->max_bot_turns) {
            $this->handover($c, 'max_turns');

            return $this->recordRun($c, $m, engine: 'limit', decision: 'handover');
        }

        if ($peeked !== null) {
            // Only now does the rule actually get used to reply, so only
            // now does it earn its `hits` increment.
            $rule = $this->rules->match($text, 'message', $c->platform) ?? $peeked;

            return $this->runRule($c, $m, $rule);
        }

        if (! $settings->ai_enabled) {
            $this->handover($c, 'no_rule');

            return $this->recordRun($c, $m, engine: 'none', decision: 'handover');
        }

        return $this->runAi($c, $m, $settings);
    }

    public function handover(Conversation $c, string $reason): void
    {
        $c->handler = Handler::Human;
        $c->needs_human = true;
        $c->handover_at = now();
        $c->save();

        $this->outbound->sendSystem($c, 'تم تحويل المحادثة لفريق خدمة العملاء');

        $this->logger->log(ActorType::System, null, ActivityLogger::CONVERSATION_HANDOVER, null, $c, ['reason' => $reason]);

        $this->notifyActiveUsers($c, $reason);

        SafeBroadcast::send(new ConversationUpdated($c));
    }

    public function returnToBot(Conversation $c, User $u): void
    {
        $c->handler = Handler::Bot;
        $c->needs_human = false;
        $c->save();

        $this->logger->log(ActorType::User, $u, ActivityLogger::CONVERSATION_RETURN_TO_BOT, null, $c);

        SafeBroadcast::send(new ConversationUpdated($c));
    }

    private function runRule(Conversation $c, Message $m, BotRule $rule): BotRun
    {
        $replyText = ($rule->private_reply !== null && $rule->private_reply !== '')
            ? $rule->private_reply
            : ($rule->public_replies[0] ?? null);

        $decision = match ($rule->action) {
            'handover' => 'handover',
            'reply_and_handover' => 'reply_and_handover',
            default => 'reply',
        };

        if (in_array($decision, ['reply', 'reply_and_handover'], true) && $replyText) {
            $this->trySendBot($c, $replyText);
        }

        if (in_array($decision, ['handover', 'reply_and_handover'], true)) {
            $this->handover($c, 'rule');
        }

        $this->logger->log(ActorType::Bot, null, ActivityLogger::BOT_RULE_MATCHED, $rule, $c, ['rule_id' => $rule->id, 'rule_name' => $rule->name]);

        return $this->recordRun($c, $m, engine: 'rule', decision: $decision, ruleId: $rule->id, replyText: $replyText);
    }

    private function runAi(Conversation $c, Message $m, BotSetting $settings): BotRun
    {
        try {
            $classification = $this->ai->classify($m->body);
        } catch (Throwable) {
            $this->handover($c, 'ai_error');

            return $this->recordRun($c, $m, engine: 'ai', decision: 'handover');
        }

        $intent = $classification->intent->value;
        $confidence = $classification->confidence;
        $classifyModel = $classification->model !== '' ? $classification->model : null;
        $classifyCost = $this->tokenCost($classification->model, $classification->inputTokens, $classification->outputTokens);

        if ($confidence < (float) $settings->min_confidence || $classification->needsHuman) {
            $this->handover($c, 'ai_low_confidence');

            return $this->recordRun(
                $c, $m,
                engine: 'ai',
                decision: 'handover',
                intent: $intent,
                confidence: $confidence,
                model: $classifyModel,
                inputTokens: $classification->inputTokens,
                outputTokens: $classification->outputTokens,
                cost: $classifyCost,
                latencyMs: $classification->latencyMs,
            );
        }

        if ($classification->intent === CommentIntent::Complaint) {
            $this->handover($c, 'complaint');

            return $this->recordRun(
                $c, $m,
                engine: 'ai',
                decision: 'handover',
                intent: $intent,
                confidence: $confidence,
                model: $classifyModel,
                inputTokens: $classification->inputTokens,
                outputTokens: $classification->outputTokens,
                cost: $classifyCost,
                latencyMs: $classification->latencyMs,
            );
        }

        $catalogLines = $this->catalog->linesFor((string) $m->body);
        $history = $this->buildHistory($c);

        try {
            $reply = $this->ai->reply($history, $catalogLines, (string) ($settings->system_prompt ?? ''));
        } catch (Throwable) {
            $this->handover($c, 'ai_error');

            return $this->recordRun(
                $c, $m,
                engine: 'ai',
                decision: 'handover',
                intent: $intent,
                confidence: $confidence,
                model: $classifyModel,
                inputTokens: $classification->inputTokens,
                outputTokens: $classification->outputTokens,
                cost: $classifyCost,
                latencyMs: $classification->latencyMs,
            );
        }

        $replyCost = $this->tokenCost($reply->model, $reply->inputTokens, $reply->outputTokens);
        $cost = round($classifyCost + $replyCost, 4);
        $inputTokens = $classification->inputTokens + $reply->inputTokens;
        $outputTokens = $classification->outputTokens + $reply->outputTokens;
        $latencyMs = $classification->latencyMs + $reply->latencyMs;

        if ($reply->action !== 'reply' || ! $this->guard->isSafe($reply->text, $catalogLines)) {
            $this->handover($c, 'ai_guard');

            return $this->recordRun(
                $c, $m,
                engine: 'ai',
                decision: 'handover',
                intent: $intent,
                confidence: $confidence,
                model: $reply->model,
                replyText: $reply->text,
                inputTokens: $inputTokens,
                outputTokens: $outputTokens,
                cost: $cost,
                latencyMs: $latencyMs,
            );
        }

        try {
            $this->outbound->sendBot($c, $reply->text);
        } catch (WindowClosedException) {
            $this->handover($c, 'window_closed');

            return $this->recordRun(
                $c, $m,
                engine: 'ai',
                decision: 'handover',
                intent: $intent,
                confidence: $confidence,
                model: $reply->model,
                replyText: $reply->text,
                inputTokens: $inputTokens,
                outputTokens: $outputTokens,
                cost: $cost,
                latencyMs: $latencyMs,
            );
        }

        $this->logger->log(ActorType::Bot, null, ActivityLogger::BOT_AI_REPLY, null, $c, ['intent' => $intent, 'confidence' => $confidence, 'model' => $reply->model]);

        return $this->recordRun(
            $c, $m,
            engine: 'ai',
            decision: 'reply',
            intent: $intent,
            confidence: $confidence,
            model: $reply->model,
            replyText: $reply->text,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            cost: $cost,
            latencyMs: $latencyMs,
        );
    }

    private function trySendBot(Conversation $c, string $text): void
    {
        try {
            $this->outbound->sendBot($c, $text);
        } catch (WindowClosedException) {
            // The rule's reply couldn't go out (window closed); the handover
            // that follows still happens and the human will see why in the log.
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
        $message = $settings->outside_hours_message;

        if (! $message) {
            return null;
        }

        $alreadySent = $c->messages()
            ->whereIn('sender_type', [SenderType::System->value, SenderType::Bot->value])
            ->where('body', $message)
            ->exists();

        if ($alreadySent) {
            return null;
        }

        $this->trySendBot($c, $message);

        $this->logger->log(ActorType::Bot, null, ActivityLogger::BOT_OUTSIDE_HOURS, null, $c);

        return $this->recordRun($c, $m, engine: $rule !== null ? 'rule' : 'system', decision: 'reply', ruleId: $rule?->id, replyText: $message);
    }

    private function matchesHandoverKeyword(string $normalized, BotSetting $settings): bool
    {
        foreach ($settings->handover_keywords ?? [] as $keyword) {
            $normalizedKeyword = $this->normalizer->normalize((string) $keyword);

            if ($normalizedKeyword !== '' && str_contains($normalized, $normalizedKeyword)) {
                return true;
            }
        }

        return false;
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

    private function notifyActiveUsers(Conversation $c, string $reason): void
    {
        $platform = $c->platform instanceof Platform ? $c->platform : Platform::from((string) $c->platform);

        User::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $u) => $u->canAccessPlatform($platform))
            ->each(function (User $u) use ($c, $reason) {
                SafeBroadcast::send(new UserNotified($u->id, 'conversation.handover', [
                    'conversation_id' => $c->id,
                    'reason' => $reason,
                ]));
            });
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
