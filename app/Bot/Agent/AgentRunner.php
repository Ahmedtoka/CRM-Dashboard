<?php

namespace App\Bot\Agent;

use App\Bot\Catalog\ProductBrowser;
use App\Bot\Flow\BurstPolicy;
use App\Bot\Flow\TurnRunner;
use App\Bot\Flows\FlowEngine;
use App\Bot\Flows\HumanHandover;
use App\Bot\Language\ConversationLanguage;
use App\Bot\PriceGuard;
use App\Enums\Handler;
use App\Enums\SenderType;
use App\Inbox\OutboundService;
use App\Inbox\WindowClosedException;
use App\Models\BotKnowledgeEntry;
use App\Models\BotRun;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One free-text turn answered by the store agent (owner, 2026-09-22). Returns null when the agent
 * is off, fails, or says something it has no evidence for — the caller then runs the older
 * TurnRunner, so the customer is never left without an answer.
 */
class AgentRunner
{
    private const HISTORY = 14;

    public function __construct(
        private readonly StoreAgent $agent,
        private readonly OutboundService $outbound,
        private readonly PriceGuard $prices,
        private readonly ProductBrowser $products,
        private readonly ConversationLanguage $language,
    ) {}

    /** @param  bool  $insideFlow  a question asked in the middle of a guided flow: answer only, the flow resumes after */
    public function run(Conversation $c, Collection $burst, bool $insideFlow = false): ?BotRun
    {
        if (! StoreAgent::enabled()) {
            return null;
        }

        $texts = $burst->pluck('body')->map(fn ($b) => trim((string) $b))->filter()->values()->all();

        if ($texts === []) {
            return null; // photos without words: the older path knows what to do with them
        }

        // Speed (2026-09-16): a lone «شكرا» / «مساء الفل» is answered from its script at once, without a model call.
        if (collect($texts)->every(fn (string $t) => app(BurstPolicy::class)->socialIntent($t, 2) !== null)) {
            return null;
        }

        $started = hrtime(true);

        try {
            $outcome = $this->agent->answer($c, $this->messages($c, $burst, $texts), $this->language->of($c), $insideFlow);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if ($outcome->reply === '' && $outcome->flow === null && $outcome->handover === null) {
            return null;
        }

        if ($outcome->reply !== '' && ! $this->grounded($outcome)) {
            Log::warning('agent.ungrounded_reply', ['conversation_id' => $c->id, 'reply' => $outcome->reply]);

            return null;
        }

        if ($c->refresh()->handler !== Handler::Bot) {
            return $this->record($c, $texts, $outcome, 'human_took_over', false, $started);
        }

        $sent = $this->deliver($c, $outcome, $insideFlow);

        if ($outcome->handover !== null) {
            app(HumanHandover::class)->handover($c, null, implode("\n", $texts), $outcome->handover['reason'], 'agent', array_filter([$outcome->handover['summary']]));
        } elseif ($outcome->flow !== null && ! $insideFlow) {
            app(FlowEngine::class)->start($c, $outcome->flow, delayMs: $sent ? 800 : 0);
        }

        return $this->record($c, $texts, $outcome, match (true) {
            $outcome->handover !== null => $sent ? 'reply_and_handover' : 'handover',
            $outcome->flow !== null && ! $insideFlow => 'flow_started',
            default => 'reply',
        }, $sent, $started);
    }

    private function deliver(Conversation $c, AgentOutcome $o, bool $insideFlow): bool
    {
        // «القائمة» rides a plain answer only: a flow prompt or the transfer sentence follows the others.
        $menu = ! $insideFlow && $o->handover === null && $o->flow === null;
        $buttons = $menu ? [TurnRunner::MENU_BUTTON] : [];

        try {
            if ($o->products->isNotEmpty()) {
                $this->products->show($c, $o->reply, $o->products, menuButton: $menu);

                return true;
            }

            if ($o->reply === '') {
                return false;
            }

            $this->outbound->sendBot($c, $o->reply, 0, false, $o->branchCards === null ? $buttons : []);

            if ($o->branchCards !== null) {
                $fallback = collect($o->branchCards['cards'])->pluck('text')->filter()->implode("\n\n");
                $this->outbound->sendBot($c, $fallback !== '' ? $fallback : $o->reply, 600, true, $buttons, $o->branchCards);
            }

            return true;
        } catch (WindowClosedException) {
            return false;
        }
    }

    /** Every price-like number and every link in the reply must come from a tool result or the owner's knowledge. */
    private function grounded(AgentOutcome $o): bool
    {
        $knowledge = BotKnowledgeEntry::query()->where('is_active', true)->pluck('body')->all();
        $evidence = [...$o->evidence, ...$knowledge, BotSetting::current()->storeUrl()];

        if (! $this->prices->isSafe($o->reply, $evidence)) {
            return false;
        }

        preg_match_all('~https?://[^\s<>"«»]+~u', $o->reply, $m);
        $haystack = implode("\n", $evidence);

        foreach ($m[0] as $url) {
            if (! str_contains($haystack, rtrim($url, '.,،؛)'))) {
                return false;
            }
        }

        return true;
    }

    /**
     * The conversation as alternating user/assistant turns; the burst is the last user turn.
     *
     * @param  list<string>  $texts
     * @return list<array{role:'user'|'assistant', content:string}>
     */
    private function messages(Conversation $c, Collection $burst, array $texts): array
    {
        $history = $c->messages()
            ->when($burst->first()?->id, fn ($q, $id) => $q->where('id', '<', $id))
            ->whereIn('sender_type', [SenderType::Customer->value, SenderType::Bot->value, SenderType::User->value])
            ->orderByDesc('id')->limit(self::HISTORY)->get()->reverse()
            ->map(fn (Message $m) => ['role' => $m->sender_type === SenderType::Customer ? 'user' : 'assistant', 'content' => trim((string) $m->body)])
            ->filter(fn (array $m) => $m['content'] !== '')
            ->values()->all();

        $history[] = ['role' => 'user', 'content' => implode("\n", $texts)];
        $merged = [];

        foreach ($history as $m) {
            $last = array_key_last($merged);

            if ($last !== null && $merged[$last]['role'] === $m['role']) {
                $merged[$last]['content'] .= "\n".$m['content'];
            } elseif ($merged !== [] || $m['role'] === 'user') {
                $merged[] = $m; // the API wants the first turn to be the customer's
            }
        }

        return $merged;
    }

    /** @param  list<string>  $texts */
    private function record(Conversation $c, array $texts, AgentOutcome $o, string $decision, bool $sent, int $started): BotRun
    {
        $price = config('crm.anthropic.prices', [])[$o->model] ?? null;

        return BotRun::create([
            'conversation_id' => $c->id,
            'trigger_message' => implode("\n", $texts),
            'engine' => 'agent',
            'model' => $o->model,
            'intent' => $o->toolsUsed !== [] ? mb_substr(implode(',', array_unique($o->toolsUsed)), 0, 190) : null,
            'confidence' => null,
            'decision' => $decision,
            'reply_text' => $sent ? $o->reply : null,
            'input_tokens' => $o->inputTokens,
            'output_tokens' => $o->outputTokens,
            'cost_usd' => $price === null ? 0 : round(($o->billedInputTokens / 1_000_000) * $price['input'] + ($o->outputTokens / 1_000_000) * $price['output'], 4),
            'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ]);
    }
}
