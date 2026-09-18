<?php

namespace App\Bot;

use App\Bot\Ai\AiResponder;
use App\Bot\Ai\MessageClassifier;
use App\Bot\Knowledge\KnowledgeBase;
use App\Bot\Knowledge\SizeChart;
use App\Enums\BotIntent;
use App\Enums\Platform;
use App\Models\BotSetting;
use Throwable;

/**
 * "اسألي البوت" (spec §4.2): runs the message-bot pipeline for a typed text
 * and reports what would happen — without sending, writing messages, notes
 * or bot runs, and without counting rule hits.
 */
final class BotPreview
{
    public function __construct(
        private readonly HandoverSignals $signals,
        private readonly RuleEngine $rules,
        private readonly KnowledgeBase $knowledge,
        private readonly MessageClassifier $classifier,
        private readonly Grounding\BotContextBuilder $context,
        private readonly AiResponder $ai,
        private readonly PriceGuard $guard,
    ) {}

    /** @return array{reply: ?string, would_handover: bool, reason: ?string, intent: ?string, grounding: list<string>} */
    public function run(string $text, Platform $platform): array
    {
        $settings = BotSetting::current();

        if ($this->signals->matchesKeyword($text, $settings->handover_keywords)) {
            return $this->result(null, 'keyword');
        }

        if (($signal = $this->signals->detect($text)) !== null) {
            return $this->result(null, $signal, $signal === 'size_recommendation' ? BotIntent::SizeRecommendation->value : BotIntent::Purchase->value);
        }

        if (($rule = $this->rules->peek($text, 'message', $platform)) !== null) {
            if ($rule->sends_size_chart) {
                return $this->result(SizeChart::fromSettings($settings)->toText(), null, BotIntent::SizeChart->value);
            }

            $reply = $rule->private_reply ?: ($rule->public_replies[0] ?? null);
            if ($rule->knowledge_key) {
                $reply = $this->knowledge->get($rule->knowledge_key)?->body ?? $reply;
            }

            if ($rule->action === 'reply') {
                return $reply ? $this->result($reply, null) : $this->result(null, 'no_rule');
            }

            return $this->result($rule->action === 'reply_and_handover' ? $reply : null, 'rule');
        }

        if (! $settings->ai_enabled) {
            return $this->result(null, 'no_rule');
        }

        try {
            $cl = $this->classifier->classifyMessage($text);
        } catch (Throwable) {
            return $this->result(null, 'ai_error');
        }

        $intent = $cl->intent->value;
        $ctx = $this->context->build($text, $cl->intent);

        if (($reason = $cl->handoverReason((float) $settings->min_confidence)) !== null) {
            return $this->result(null, $reason, $intent, $ctx->lines);
        }

        if ($cl->intent === BotIntent::SizeChart) {
            return $this->result(SizeChart::fromSettings($settings)->toText(), null, $intent, $ctx->lines);
        }

        if ($ctx->missingProductFor($cl->intent)) {
            return $this->result(null, 'no_product_match', $intent, $ctx->lines);
        }

        $prompt = trim((string) $settings->system_prompt) !== '' ? (string) $settings->system_prompt : BotSetting::DEFAULT_SYSTEM_PROMPT;

        try {
            $reply = $this->ai->reply([['role' => 'customer', 'text' => $text]], $ctx->lines, $prompt);
        } catch (Throwable) {
            return $this->result(null, 'ai_error', $intent, $ctx->lines);
        }

        if ($reply->action !== 'reply' || trim($reply->text) === '') {
            return $this->result(null, 'ai_handover', $intent, $ctx->lines);
        }

        if (! $this->guard->isSafe($reply->text, $ctx->lines)) {
            return $this->result(null, 'ai_guard', $intent, $ctx->lines);
        }

        return $this->result($reply->text, null, $intent, $ctx->lines);
    }

    /**
     * @param  list<string>  $grounding
     * @return array{reply: ?string, would_handover: bool, reason: ?string, intent: ?string, grounding: list<string>}
     */
    private function result(?string $reply, ?string $reason, ?string $intent = null, array $grounding = []): array
    {
        return ['reply' => $reply, 'would_handover' => $reason !== null, 'reason' => $reason, 'intent' => $intent, 'grounding' => array_values($grounding)];
    }
}
