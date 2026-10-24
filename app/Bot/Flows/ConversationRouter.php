<?php

namespace App\Bot\Flows;

use App\Bot\Agent\AgentRunner;
use App\Bot\Agent\StoreAgent;
use App\Bot\Agent\TierProfile;
use App\Bot\Episodes\ConversationEpisode;
use App\Bot\Flow\BurstPolicy;
use App\Bot\Flow\TurnRunner;
use App\Bot\Router\HybridRouter;
use App\Bot\Router\TaskOrchestrator;
use App\Enums\Handler;
use App\Enums\SenderType;
use App\Inbox\OutboundService;
use App\Inbox\WindowClosedException;
use App\Models\BotFlow;
use App\Models\BotRun;
use App\Models\Conversation;
use App\Models\Message;
use App\Queue\RatingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The first stop of a bot turn after the hours / keyword / turn-limit gates
 * (agent rebuild, Architecture): button taps and pending yes/no answers, an
 * active guided flow, and greetings or "منيو" → the main menu. Anything else
 * returns null and the agent (TurnRunner) takes the turn.
 *
 * A greeting is greeted back the same way first (GreetingMirror, 2026-09-21):
 * «وعليكم السلام ورحمة الله» / «صباح النور» / «أهلاً بيكي» goes above the
 * `{time_greeting}` line of `script.greeting` and above the menu.
 */
class ConversationRouter
{
    public const MAIN_MENU = 'main_menu';

    /** Normalized words that open the menu besides FlowAnswerResolver's exit words. */
    private const START_WORDS = ['ابدا', 'start'];

    public function __construct(
        private readonly FlowEngine $flows,
        private readonly ButtonMatcher $buttons,
        private readonly FlowAnswerResolver $resolver,
        private readonly FlowPrompter $prompter,
        private readonly OutboundService $outbound,
        private readonly BurstPolicy $burstPolicy,
        private readonly HumanHandover $human,
        private readonly GreetingMirror $mirrors,
    ) {}

    /**
     * Round 4 (2026-10-24, chat 214): a button tap that is NOT the last message of the burst — she
     * tapped and typed while the worker was busy, or typed and then tapped. Each tap is run here
     * first (a menu option that starts a flow takes the words typed around it as its prefill, so the
     * order is not asked again), and only what is left after the last tap goes on to the flow or the
     * model: a button title never reaches a model as if she had typed it.
     *
     * @return Collection<int, Message> the messages still to answer (empty when the taps said it all)
     */
    public function consumeTaps(Conversation $c, Collection $burst): Collection
    {
        $messages = $burst->values();
        $last = $messages->last();
        $taps = $messages->filter(fn (Message $m) => filled($m->payload) && ! str_starts_with((string) $m->payload, RatingService::PAYLOAD_PREFIX));

        if (! $last instanceof Message || $taps->isEmpty() || $taps->last()->id === $last->id) {
            return $messages;
        }

        $tap = $taps->last();
        $index = $messages->search(fn (Message $m) => $m->id === $tap->id);
        $head = $messages->slice(0, $index + 1)->values();
        $tail = $messages->slice($index + 1)->values();
        $tailText = $tail->map(fn (Message $m) => trim((string) $m->body))->filter()->implode("\n");
        $payload = (string) $tap->payload;

        // «المرتجع والاستبدال» + «عايزة ارجع الفستان من اوردر 209129»: the flow starts from her words
        // (the main menu she tapped from is not a flow in progress).
        if (str_starts_with($payload, 'flow:') && $tailText !== '' && (! $this->flows->isActive($c) || $this->flows->atMenu($c))) {
            $flowKey = substr($payload, 5);

            if ($this->flows->startWithWords($c, $flowKey, implode("\n", $head->merge($tail)->map(fn (Message $m) => trim((string) $m->body))->filter()->all()))) {
                $filled = array_intersect_key((array) (FlowState::flow($c)['data'] ?? []), array_flip(['order_ref_hint', 'order_number', 'item_hints', 'selected_items', 'request_kind']));

                // Her words were taken by the start (the order, the pieces): nothing is left to answer.
                // Otherwise the flow asks its first question and her words are read as its answer.
                return $filled !== [] || ! $this->flows->isActive($c) ? collect() : $tail;
            }
        }

        $this->route($c, $head);

        return $c->refresh()->handler === Handler::Bot ? $tail : collect();
    }

    public function route(Conversation $c, Collection $burst): ?BotRun
    {
        $last = $burst->last();

        if (! $last instanceof Message) {
            return null;
        }

        $started = hrtime(true);
        $texts = $burst->map(fn (Message $m) => trim((string) $m->body))->filter(fn (string $t) => $t !== '')->values()->all();

        // Round 4: «عايزة ارجع الفستان من اوردر 209129» typed, then the menu option tapped (chat 214):
        // the flow starts filled from the words that came with the tap — from the menu or from nowhere.
        $tapped = filled($last->payload) && str_starts_with((string) $last->payload, 'flow:') ? (string) $last->payload : null;
        $words = $tapped !== null ? $burst->filter(fn (Message $m) => $m->id !== $last->id)->map(fn (Message $m) => trim((string) $m->body))->filter()->implode("\n") : '';

        if ($tapped !== null && $words !== '' && (! $this->flows->isActive($c) || $this->flows->atMenu($c))
            && $this->flows->startWithWords($c, substr($tapped, 5), $words)) {
            return $this->record($c, $texts, $started, 'button');
        }

        // 2. An active flow reads the burst itself (payloads, typed answers, questions).
        if ($this->flows->isActive($c)) {
            $result = $this->flows->handle($c, $burst);

            // A question while a menu is up (2026-09-22): the menu is left and the agent answers it as
            // any other message — no «نرجع لـ…», no detour count, no offer of a person.
            if ($result->question !== null && $this->flows->atMenu($c) && StoreAgent::enabled()) {
                FlowState::clear($c);

                return null;
            }

            if ($result->question !== null) {
                $runner = app(TurnRunner::class);
                // The store agent answers a question asked mid-flow too (2026-09-22); the turn runner is its safety net.
                // Hybrid mode (owner B8): tier 1 answers, and the old turn runner only behind its switch.
                $hybrid = HybridRouter::mode() === 'hybrid';
                $run = app(AgentRunner::class)->run($c, $burst, insideFlow: true, profile: $hybrid ? TierProfile::tier('1') : null)
                    ?? (! $hybrid || config('crm.bot.router.legacy_fallback', false) ? $runner->run($c, $burst, flowContext: true) : null);

                if ($c->refresh()->handler === Handler::Bot && $this->flows->isActive($c)) {
                    // §6.1: the answer, then «نرجع لطلب المرتجع» with the step's question
                    // and buttons again — or a person once she has been round twice.
                    $this->flows->returnToFlow($c, $runner->followUpDelayMs());
                } elseif ($c->handler !== Handler::Bot) {
                    FlowState::clear($c);
                }

                // Hybrid mode with no answer: the flow's question came back, and nothing else runs this turn.
                return $run ?? $this->record($c, $texts, $started, 'flow');
            }

            if ($result->handled) {
                return $this->record($c, $texts, $started, 'flow');
            }
        } else {
            // 1. A tapped (or typed) button, or a typed yes/no to a pending offer.
            $payload = $this->buttons->match($c, $last);

            if ($payload === null && FlowState::confirm($c) !== null) {
                $payload = $this->resolver->yesNo(implode("\n", $texts));

                if ($payload === null) {
                    FlowState::setConfirm($c, null);
                }
            }

            if ($payload !== null && $this->runPayload($c, $payload)) {
                return $this->record($c, $texts, $started, 'button');
            }

            // Round b1: a button of a request that is over (an old picture card) — answered here, at
            // once, never handed to a model that only reads «رجّع القطعة دي».
            if ($payload !== null && app(StaleTap::class)->answer($c, $payload)) {
                return $this->record($c, $texts, $started, 'stale_tap');
            }
        }

        // 2b. She asks for a person in words (the human_request intent's words, flow 7): «محتاجة إيه؟» first.
        if ($texts !== [] && ! $this->flows->isActive($c) && BotFlow::active(self::MAIN_MENU) !== null && $this->human->isHumanRequest(implode("\n", $texts))) {
            $this->human->requested($c, $burst, implode("\n", $texts));

            return $this->record($c, $texts, $started, 'button');
        }

        // 3. Greetings or a menu word open the main menu (the greeting script first on the first reply).
        if ($texts !== [] && $this->wantsMenu($texts) && BotFlow::active(self::MAIN_MENU) !== null) {
            // 2026-09-21: her own greeting is mirrored first, above the {time_greeting} line and the menu.
            $mirror = $this->mirrors->line($texts[0]);
            $greeting = $this->isFirstBotReply($c) ? $this->prompter->script('greeting') : null;
            $opening = trim(implode("\n", array_filter([$mirror, $greeting])));

            if ($opening !== '') {
                $this->send($c, $opening);
            }

            $this->flows->start($c, self::MAIN_MENU);

            return $this->record($c, $texts, $started, 'menu');
        }

        return null;
    }

    /** runPayload, plus the main menu after a "no" to an offer made outside a flow (never silence). */
    private function runPayload(Conversation $c, string $payload): bool
    {
        // «بعدين» to the next request of her message has its own reply and summary (review #12); «لأ» to
        // «بتسألي عن طلب …؟» (round 4) is followed by the answer to her message, not by the menu.
        $offerOutsideFlow = $payload === 'no' && ! in_array(FlowState::confirm($c), [null, TaskOrchestrator::CONFIRM, ConversationEpisode::CONFIRM], true) && ! $this->flows->isActive($c);

        if (! $this->flows->runPayload($c, $payload)) {
            return false;
        }

        if ($offerOutsideFlow && ! $this->flows->isActive($c)) {
            $this->flows->start($c, self::MAIN_MENU);
        }

        return true;
    }

    /** @param  list<string>  $texts */
    private function wantsMenu(array $texts): bool
    {
        foreach ($texts as $t) {
            if ($this->resolver->exitWord($t) === 'menu' || in_array($this->resolver->clean($t), self::START_WORDS, true)) {
                return true;
            }
        }

        foreach ($texts as $t) {
            if ($this->burstPolicy->socialIntent($t) !== 'greeting') {
                return false;
            }
        }

        return true;
    }

    private function isFirstBotReply(Conversation $c): bool
    {
        return ! $c->messages()->where('sender_type', SenderType::Bot->value)->exists();
    }

    private function send(Conversation $c, string $text): void
    {
        try {
            $this->outbound->sendBot($c, $text, 0, true);
        } catch (WindowClosedException) {
            Log::info('router.window_closed', ['conversation_id' => $c->id]);
        }
    }

    /** @param  list<string>  $texts */
    private function record(Conversation $c, array $texts, int $started, string $decision): BotRun
    {
        $c->refresh();

        return BotRun::create([
            'conversation_id' => $c->id,
            'trigger_message' => implode("\n", $texts),
            'engine' => 'flow_engine',
            'model' => null,
            'intent' => FlowState::flow($c)['key'] ?? null,
            'confidence' => null,
            'decision' => $c->handler === Handler::Bot ? $decision : 'handover',
            'reply_text' => null,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_usd' => 0,
            'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ]);
    }
}
