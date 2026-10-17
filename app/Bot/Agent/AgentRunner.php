<?php

namespace App\Bot\Agent;

use App\Bot\Catalog\ProductBrowser;
use App\Bot\Flow\BurstPolicy;
use App\Bot\Flow\TurnRunner;
use App\Bot\Flows\HumanHandover;
use App\Bot\Jobs\BotHoldNotice;
use App\Bot\Language\ConversationLanguage;
use App\Bot\Router\TaskOrchestrator;
use App\Enums\Handler;
use App\Enums\SenderType;
use App\Inbox\OutboundService;
use App\Inbox\WindowClosedException;
use App\Models\BotRun;
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
        private readonly ProductBrowser $products,
        private readonly ConversationLanguage $language,
    ) {}

    /** @param  bool  $insideFlow  a question asked in the middle of a guided flow: answer only, the flow resumes after */
    public function run(Conversation $c, Collection $burst, bool $insideFlow = false, ?TierProfile $profile = null): ?BotRun
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
        // «ثانية واحدة» if the model is slow (owner B2): this path always calls a model.
        BotHoldNotice::arm($c);

        $messages = $this->messages($c, $burst, $texts);
        $outcome = $this->attempt($c, $messages, $insideFlow, $profile);

        // Tier 1 asked for help (the `escalate` tool): one try on tier 2; a Sonnet profile that asks
        // (the legacy agent, or past the Sonnet cap) hands her to a person instead (review #6).
        if ($outcome?->escalate !== null) {
            $tier2 = TierProfile::tier('2');
            $reason = $outcome->escalate;
            $outcome = ($profile?->tier === '1' && $tier2->tier === '2') ? $this->attempt($c, $messages, $insideFlow, $tier2) : null;

            if ($outcome === null || $outcome->escalate !== null) {
                $outcome ??= new AgentOutcome;
                $outcome->reply = '';
                $outcome->escalate = null;
                $outcome->handover = ['reason' => 'escalated', 'summary' => $reason];
            }
        }

        // Failed, refused or cut off: the older path answers instead (legacy mode).
        if ($outcome === null || $outcome->refused || $outcome->truncated
            || ($outcome->reply === '' && $outcome->flow === null && $outcome->handover === null)) {
            return null;
        }

        if (($failed = app(ReplyGuards::class)->failure($outcome, $texts)) !== null) {
            Log::warning('agent.ungrounded_reply', ['conversation_id' => $c->id, 'guard' => $failed, 'reply' => $outcome->reply]);

            return null;
        }

        return $this->complete($c, $texts, $outcome, $insideFlow, $started);
    }

    private function attempt(Conversation $c, array $messages, bool $insideFlow, ?TierProfile $profile): ?AgentOutcome
    {
        try {
            return $this->agent->answer($c, $messages, $this->language->of($c), $insideFlow, $profile);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Sends a checked outcome and does what its tools asked: a handover, or the guided flows —
     * the ones the agent picked (one task per order she named) and the $tasks the router planned.
     *
     * @param  list<string>  $texts
     * @param  list<array<string, mixed>>  $tasks  directed tasks planned by the router (bot hybrid)
     * @param  list<array<string, mixed>>  $answered  tasks this reply answered (they count for the summary)
     */
    public function complete(Conversation $c, array $texts, AgentOutcome $outcome, bool $insideFlow, int $started, string $engine = 'agent', array $tasks = [], array $answered = [], ?string $plan = null): BotRun
    {
        if ($c->refresh()->handler !== Handler::Bot) {
            return $this->record($c, $texts, $outcome, 'human_took_over', false, $started, $engine, $plan);
        }

        if ($outcome->flow !== null && ! $insideFlow && $tasks === []) {
            // Bot hybrid R1/R6: filled from her words, one task per order she named.
            $tasks = TaskOrchestrator::flowTasks($outcome->flow, implode("\n", $texts));
        }

        $flows = ! $insideFlow && $outcome->handover === null && $tasks !== [];
        $sent = $this->deliver($c, $outcome, $insideFlow, $flows);

        if ($outcome->handover !== null) {
            app(HumanHandover::class)->handover($c, null, implode("\n", $texts), $outcome->handover['reason'], 'agent', array_filter([$outcome->handover['summary']]));
        } elseif ($flows) {
            app(TaskOrchestrator::class)->start($c, $tasks, $sent ? 800 : 0, $answered);
        }

        return $this->record($c, $texts, $outcome, match (true) {
            $outcome->handover !== null => $sent ? 'reply_and_handover' : 'handover',
            $flows => 'flow_started',
            default => 'reply',
        }, $sent, $started, $engine, $plan);
    }

    private function deliver(Conversation $c, AgentOutcome $o, bool $insideFlow, bool $flows = false): bool
    {
        // «القائمة» rides a plain answer only: a flow prompt or the transfer sentence follows the others.
        $menu = ! $insideFlow && $o->handover === null && $o->flow === null && ! $flows;
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

    /**
     * The conversation as alternating user/assistant turns; the burst is the last user turn.
     *
     * @param  list<string>  $texts
     * @return list<array{role:'user'|'assistant', content:string}>
     */
    public function messages(Conversation $c, Collection $burst, array $texts): array
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
    /** @param  string|null  $plan  the router's «kinds|reason» (bot hybrid): kept before the tools used */
    private function record(Conversation $c, array $texts, AgentOutcome $o, string $decision, bool $sent, int $started, string $engine = 'agent', ?string $plan = null): BotRun
    {
        $tools = $o->toolsUsed !== [] ? implode(',', array_unique($o->toolsUsed)) : null;

        return BotRun::create([
            'conversation_id' => $c->id,
            'trigger_message' => implode("\n", $texts),
            'engine' => $engine,
            'model' => $o->model,
            'tier' => $o->tier,
            'intent' => ($intent = $plan !== null ? $plan.($tools !== null ? '|tools:'.$tools : '') : $tools) !== null ? mb_substr($intent, 0, 190) : null,
            'confidence' => null,
            'decision' => $decision,
            'reply_text' => $sent ? $o->reply : null,
            'input_tokens' => $o->inputTokens,
            'output_tokens' => $o->outputTokens,
            'cost_usd' => round($o->costUsd, 6),
            'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ]);
    }
}
