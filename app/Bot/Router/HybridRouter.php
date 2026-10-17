<?php

namespace App\Bot\Router;

use App\Bot\Agent\AgentOutcome;
use App\Bot\Agent\AgentRunner;
use App\Bot\Agent\ReplyGuards;
use App\Bot\Agent\StoreAgent;
use App\Bot\Agent\TierProfile;
use App\Bot\Ai\ModelPricing;
use App\Bot\Flow\BurstPolicy;
use App\Bot\Flow\TurnRunner;
use App\Bot\Flows\FlowLabels;
use App\Bot\Flows\FlowPrompter;
use App\Bot\Flows\FlowScripts;
use App\Bot\Flows\FlowState;
use App\Bot\Flows\HumanHandover;
use App\Bot\Flows\Returns\ReturnPrefill;
use App\Bot\Jobs\BotHoldNotice;
use App\Bot\Language\ConversationLanguage;
use App\Enums\MessageDirection;
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
 * The fast + smart bot (bot-hybrid.md §3, owner B1–B10), behind `crm.bot.router.mode = hybrid`.
 * Runs where the store agent used to (BotEngine, after the hours / keyword / limit gates, buttons,
 * an active flow, greetings and owner rules):
 *
 *   tier 0   a standing question (FaqMatcher) → the owner's script, no model;
 *   planner  Haiku turns the message into tasks (keyword planner when it fails or is late);
 *   policy   tier 1 (Haiku) or tier 2 (Sonnet, E1–E6) or a person (> 4 tasks, asked for one,
 *            angry with a threat);
 *   tasks    directed tasks (returns, cancellations, complaints) start their flows already filled,
 *            one after the other; the model's single reply answers the rest;
 *   guards   price, promise, link and order facts on every model reply; tier 1 that escalates,
 *            refuses or fails a guard gets ONE tier-2 retry; after that a safe script and the
 *            offer of a person — never the old TurnRunner unless `legacy_fallback` (owner B8).
 */
class HybridRouter
{
    /** The tier-2 retry is skipped once the job has run this long (RunBotTurn times out at 75 s). */
    private const RETRY_DEADLINE_SECONDS = 50;

    public function __construct(
        private readonly AgentRunner $runner,
        private readonly StoreAgent $agent,
        private readonly ReplyGuards $guards,
        private readonly FaqMatcher $faq,
        private readonly EscalationPolicy $policy,
        private readonly TaskOrchestrator $tasks,
        private readonly OutboundService $outbound,
        private readonly ConversationLanguage $language,
        private readonly FlowPrompter $prompter,
        private readonly TurnPlanner $planner,
        private readonly FakeTurnPlanner $keywords,
    ) {}

    public static function mode(): string
    {
        $mode = (string) config('crm.bot.router.mode', 'legacy');

        return in_array($mode, ['legacy', 'shadow', 'hybrid'], true) ? $mode : 'legacy';
    }

    public function route(Conversation $c, Collection $burst): ?BotRun
    {
        $texts = $burst->pluck('body')->map(fn ($b) => trim((string) $b))->filter()->values()->all();

        // Photos without words, a lone «شكرا» / «مساء الفل»: the scripted path answers them without a model.
        if ($texts === [] || collect($texts)->every(fn (string $t) => app(BurstPolicy::class)->socialIntent($t, 2) !== null)) {
            return app(TurnRunner::class)->run($c, $burst);
        }

        $started = hrtime(true);
        $text = implode("\n", $texts);
        $metrics = app(TurnMetrics::class);

        if (($faq = $this->faq->match($text)) !== null) {
            $metrics->tier = '0';
            $this->send($c, $faq['reply'], [TurnRunner::MENU_BUTTON]);

            return $this->record($c, $texts, 'reply', '0', $started, 'faq:'.$faq['intent'], $faq['reply']);
        }

        // No model available (no key, or the agent switched off): the scripted path, as before.
        if (! StoreAgent::enabled()) {
            return app(TurnRunner::class)->run($c, $burst);
        }

        BotHoldNotice::arm($c);
        [$plan, $planCost, $planModel] = $this->plan($c, $burst, $texts);
        $decision = $this->policy->decide($plan, $c, $text);
        $this->policy->remember($c, $plan, $text);
        $metrics->tier = $decision['tier'];
        $intent = mb_substr(implode(',', $plan->kinds()).($decision['reason'] !== null ? '|'.$decision['reason'] : ''), 0, 190);

        if ($decision['tier'] === 'handover') {
            $this->handover($c, $plan, $text, (string) $decision['reason'], $decision['priority']);

            return $this->record($c, $texts, 'handover', 'handover', $started, $intent, null, $planCost, $planModel);
        }

        $directed = $this->directedTasks($plan, $text);
        $answered = array_map(fn ($t) => ['status' => TaskQueue::ANSWERED] + TaskQueue::task($t['kind'], self::immediateLabel($t)), $plan->immediate());

        // Only guided requests, nothing delicate: their flows ask the questions, no model reply needed.
        if ($directed !== [] && $plan->immediate() === [] && $decision['tier'] === '1') {
            $metrics->tier = 'flow';
            $this->tasks->start($c, $directed);

            return $this->record($c, $texts, 'flow_started', 'flow', $started, $intent, null, $planCost, $planModel);
        }

        $outcome = $this->answer($c, $burst, $texts, $decision['tier'], $this->context($plan, $directed), $started);

        if ($outcome === null) {
            if ($directed !== []) {
                $this->tasks->start($c, $directed);

                return $this->record($c, $texts, 'flow_started', 'flow', $started, $intent, null, $planCost, $planModel);
            }

            if (config('crm.bot.router.legacy_fallback', false)) {
                return app(TurnRunner::class)->run($c, $burst);
            }

            $this->safeFallback($c);

            return $this->record($c, $texts, 'safe_fallback', $metrics->tier, $started, $intent, null, $planCost, $planModel);
        }

        $outcome->costUsd += $planCost;
        $metrics->tier = $outcome->tier;

        return $this->runner->complete($c, $texts, $outcome, false, $started, 'hybrid', $directed, $answered, $intent);
    }

    /**
     * The plan from the Haiku planner, or the keyword planner when it fails or is late (§3.9).
     *
     * @param  list<string>  $texts
     * @return array{0: TaskPlan, 1: float, 2: ?string} the plan, its cost and its model
     */
    public function plan(Conversation $c, Collection $burst, array $texts, ?array $state = null): array
    {
        $state ??= self::state($c);

        try {
            $plan = $this->planner->plan($this->history($c, $burst), $texts, $state);
            $usage = $this->planner instanceof ClaudeTurnPlanner ? $this->planner->lastUsage : [];

            return [$plan, ModelPricing::ofUsage($this->planner->model(), $usage), $this->planner->model()];
        } catch (Throwable $e) {
            Log::info('router.planner_failed', ['conversation_id' => $c->id, 'error' => $e->getMessage()]);

            return [$this->keywords->plan([], $texts, $state), 0.0, null];
        }
    }

    /** @return array{flow:?string, step:?string, last_question:?string} where the chat is now, for the planner */
    public static function state(Conversation $c): array
    {
        $flow = FlowState::flow($c);

        return [
            'flow' => $flow['key'] ?? null,
            'step' => $flow['step'] ?? null,
            'last_question' => $c->messages()->where('sender_type', SenderType::Bot->value)->latest('id')->value('body'),
        ];
    }

    /**
     * Tier 1 or 2, then (E5) one tier-2 retry when tier 1 escalated, refused, was cut off or failed a
     * guard. Null when no usable reply came.
     *
     * @param  list<string>  $texts
     */
    private function answer(Conversation $c, Collection $burst, array $texts, string $tier, string $context, int $started): ?AgentOutcome
    {
        $messages = $this->runner->messages($c, $burst, $texts);
        $language = $this->language->of($c);
        $profile = TierProfile::tier($tier);
        $first = $this->attempt($c, $messages, $language, $profile, $context);

        // Tier 1 failed outright (a timeout, an API error): tier 2 gets the turn while time allows (review #7).
        if ($first === null) {
            $second = TierProfile::tier('2');

            if ($profile->tier !== '1' || $second->tier !== '2' || (hrtime(true) - $started) / 1e9 > self::RETRY_DEADLINE_SECONDS) {
                return null;
            }

            $outcome = $this->attempt($c, $messages, $language, $second, $context);

            return $outcome !== null && ! $outcome->refused && ! $outcome->truncated && $this->guards->failure($outcome, $texts) === null && $this->usable($outcome) ? $outcome : null;
        }

        $retry = match (true) {
            $first->escalate !== null => 'escalated: '.$first->escalate,
            $first->refused => 'refused',
            $first->truncated => 'cut off',
            default => ($failed = $this->guards->failure($first, $texts)) !== null ? 'failed the '.$failed.' check' : null,
        };

        if ($retry === null) {
            return $this->usable($first) ? $first : null;
        }

        $second = TierProfile::tier('2');
        $late = (hrtime(true) - $started) / 1e9 > self::RETRY_DEADLINE_SECONDS;

        if ($profile->tier !== '1' || $second->tier !== '2' || $late) {
            // No tier 2 to ask (it already was, the Sonnet cap is reached, or no time): an escalation
            // becomes a person; anything else is not sent.
            if ($first->escalate !== null) {
                $first->reply = '';
                $first->handover = ['reason' => 'escalated', 'summary' => $first->escalate];

                return $first;
            }

            return null;
        }

        $outcome = $this->attempt($c, $messages, $language, $second, $context."\n- a colleague's draft was not usable ({$retry}): answer only from tool results and KNOWLEDGE.");

        if ($outcome === null || $outcome->refused || $outcome->truncated || $this->guards->failure($outcome, $texts) !== null || ! $this->usable($outcome)) {
            return null;
        }

        $outcome->costUsd += $first->costUsd;
        $outcome->inputTokens += $first->inputTokens;
        $outcome->outputTokens += $first->outputTokens;

        return $outcome;
    }

    private function attempt(Conversation $c, array $messages, string $language, TierProfile $profile, string $context): ?AgentOutcome
    {
        try {
            $o = $this->agent->answer($c, $messages, $language, false, $profile, $context);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        // Tier 2 asking for tier 2: a person takes it.
        if ($o->escalate !== null && $profile->tier !== '1') {
            $o->reply = '';
            $o->handover ??= ['reason' => 'escalated', 'summary' => $o->escalate];
            $o->escalate = null;
        }

        return $o;
    }

    private function usable(AgentOutcome $o): bool
    {
        return $o->reply !== '' || $o->flow !== null || $o->handover !== null || $o->products->isNotEmpty();
    }

    /**
     * The flows for the directed tasks, filled from the plan (and from her words when the planner
     * gave no pieces): one queue task per order.
     *
     * @return list<array<string, mixed>>
     */
    public function directedTasks(TaskPlan $plan, string $text): array
    {
        $directed = $plan->directed();
        $words = ReturnPrefill::fromText($text);
        $out = [];

        foreach ($directed as $t) {
            $flow = TaskPlan::FLOWS[$t['kind']];
            $ref = $t['order_refs'][0] ?? null;

            if ($flow !== 'return_exchange') {
                $out[] = TaskQueue::task($t['kind'], TaskOrchestrator::label($flow, null, $ref), $flow, array_filter(['order_ref_hint' => $ref]));

                continue;
            }

            $kind = match ($t['kind']) {
                'return', 'exchange', 'mixed' => $t['kind'],
                default => null,
            };
            $hints = array_map(fn ($i) => ['text' => $i['text'], 'action' => $i['action'] ?? (in_array($kind, ['return', 'exchange'], true) ? $kind : null), 'exchange_to' => $i['exchange_to']], $t['items']);

            // The planner gave no pieces for the only return: her own words, read by code.
            if ($hints === [] && count($directed) === 1) {
                $hints = $words['item_hints'];
            }

            $data = ReturnPrefill::flowData(['order_refs' => $ref !== null ? [$ref] : [], 'request_kind' => $kind, 'item_hints' => $hints]);
            $out[] = TaskQueue::task($kind ?? 'return_exchange', TaskOrchestrator::label($flow, $kind, $ref), $flow, $data);
        }

        return $out;
    }

    /** The CONTEXT lines the agent reads about the plan. */
    private function context(TaskPlan $plan, array $directed): string
    {
        $lines = [];

        foreach ($plan->tasks as $i => $t) {
            $flow = TaskPlan::hasFlow($t['kind']);
            $details = array_filter([
                $t['order_refs'] !== [] ? 'order #'.implode(', #', $t['order_refs']) : null,
                $t['items'] !== [] ? 'pieces: '.implode('، ', array_column($t['items'], 'text')) : null,
                $t['governorate'] !== null ? 'governorate: '.$t['governorate'] : null,
                $t['place'] !== null ? 'place: '.$t['place'] : null,
                $t['product_query'] !== null ? 'product: '.$t['product_query'] : null,
            ]);
            $lines[] = '  '.($i + 1).') '.$t['kind'].($details !== [] ? ' ('.implode('; ', $details).')' : '')
                .($flow ? ' — a guided flow handles it right after your reply: do not ask its details and do not call start_flow for it' : ' — answer it');
        }

        $out = $lines !== [] ? "- requests the system found in her message:\n".implode("\n", $lines) : '';

        if ($directed !== [] && $plan->immediate() === []) {
            $out .= "\n- nothing else is asked: reply with one short warm line about her situation, or nothing at all.";
        }

        if (in_array($plan->sentiment, ['angry', 'negative'], true)) {
            $out .= "\n- she is upset: apologise briefly and sincerely first.";
        }

        return trim($out);
    }

    /** A person now: too many requests (owner B5), she asked for one, or anger with a threat. */
    private function handover(Conversation $c, TaskPlan $plan, string $text, string $reason, string $priority): void
    {
        $lines = array_map(fn ($t) => '- '.($t['question'] ?? $t['kind']).($t['order_refs'] !== [] ? ' (#'.implode(', #', $t['order_refs']).')' : ''), $plan->tasks);

        if ($reason === 'too_many_tasks') {
            $this->send($c, $this->script('tasks_too_many'));
        } elseif ($reason === 'angry_threat') {
            $this->send($c, $this->script('bot_sorry_handover'));
        }

        app(HumanHandover::class)->handover($c, null, $text, $reason, 'human_request', $lines !== [] ? ['الطلبات في رسالتها:', ...$lines] : [], $priority);
    }

    /** No checked answer and no flow: never a made-up one — «خليني أتأكد…» and the offer of a person. */
    private function safeFallback(Conversation $c): void
    {
        $this->send($c, $this->script('bot_safe_fallback'), [FlowLabels::YES_BUTTON, FlowLabels::NO_BUTTON]);
        FlowState::setConfirm($c, 'handover_offer');
    }

    /** «الشحن لـ الجيزة», «متابعة الأوردر #209129»: how an answered request reads in the summary. */
    public static function immediateLabel(array $t): string
    {
        $ref = $t['order_refs'][0] ?? null;

        return match ($t['kind']) {
            'order_status' => 'متابعة الأوردر'.($ref !== null ? ' #'.$ref : ''),
            'shipping_fee' => 'مصاريف الشحن'.($t['governorate'] !== null ? ' لـ'.$t['governorate'] : ''),
            'branches' => 'الفروع'.($t['place'] !== null ? ' في '.$t['place'] : ''),
            'product' => $t['product_query'] !== null ? 'سؤال عن '.$t['product_query'] : 'سؤال عن المنتجات',
            default => 'سؤال حضرتك',
        };
    }

    /** @return list<array{role:'customer'|'agent', text:string}> the last 6 messages before the burst */
    private function history(Conversation $c, Collection $burst): array
    {
        $firstId = $burst->first()?->id;

        return $c->messages()->when($firstId, fn ($q) => $q->where('id', '<', $firstId))
            ->whereIn('sender_type', [SenderType::Customer->value, SenderType::Bot->value, SenderType::User->value])
            ->orderByDesc('id')->limit(6)->get()->reverse()->values()
            ->map(fn (Message $m) => ['role' => $m->direction === MessageDirection::In ? 'customer' : 'agent', 'text' => (string) $m->body])
            ->filter(fn ($m) => trim($m['text']) !== '')->values()->all();
    }

    private function script(string $key): string
    {
        return $this->prompter->script($key) ?? (string) (FlowScripts::all()[$key]['body'] ?? '');
    }

    /** @param  list<array{title:string, payload:string}>  $buttons */
    private function send(Conversation $c, string $text, array $buttons = []): void
    {
        if (trim($text) === '') {
            return;
        }

        try {
            $this->outbound->sendBot($c, $text, 0, true, $buttons);
        } catch (WindowClosedException) {
            Log::info('router.window_closed', ['conversation_id' => $c->id]);
        }
    }

    /** @param  list<string>  $texts */
    private function record(Conversation $c, array $texts, string $decision, string $tier, int $started, ?string $intent, ?string $reply = null, float $cost = 0.0, ?string $model = null): BotRun
    {
        return BotRun::create([
            'conversation_id' => $c->id,
            'trigger_message' => implode("\n", $texts),
            'engine' => 'hybrid',
            'model' => $model,
            'tier' => $tier,
            'intent' => $intent !== null && $intent !== '' ? mb_substr($intent, 0, 190) : null,
            'confidence' => null,
            'decision' => $decision,
            'reply_text' => $reply,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_usd' => round($cost, 6),
            'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ]);
    }
}
