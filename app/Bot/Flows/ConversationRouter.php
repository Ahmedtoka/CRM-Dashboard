<?php

namespace App\Bot\Flows;

use App\Bot\Flow\BurstPolicy;
use App\Bot\Flow\TurnRunner;
use App\Enums\Handler;
use App\Enums\SenderType;
use App\Inbox\OutboundService;
use App\Inbox\WindowClosedException;
use App\Models\BotFlow;
use App\Models\BotRun;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The first stop of a bot turn after the hours / keyword / turn-limit gates
 * (agent rebuild, Architecture): button taps and pending yes/no answers, an
 * active guided flow, and greetings or "منيو" → the main menu. Anything else
 * returns null and the agent (TurnRunner) takes the turn.
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
    ) {}

    public function route(Conversation $c, Collection $burst): ?BotRun
    {
        $last = $burst->last();

        if (! $last instanceof Message) {
            return null;
        }

        $started = hrtime(true);
        $texts = $burst->map(fn (Message $m) => trim((string) $m->body))->filter(fn (string $t) => $t !== '')->values()->all();

        // 2. An active flow reads the burst itself (payloads, typed answers, questions).
        if ($this->flows->isActive($c)) {
            $result = $this->flows->handle($c, $burst);

            if ($result->question !== null) {
                $runner = app(TurnRunner::class);
                $run = $runner->run($c, $burst, flowContext: true);

                if ($c->refresh()->handler === Handler::Bot && $this->flows->isActive($c)) {
                    $this->flows->repromptCurrent($c, $runner->followUpDelayMs());
                } elseif ($c->handler !== Handler::Bot) {
                    FlowState::clear($c);
                }

                return $run;
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
        }

        // 2b. She asks for a person in words (the human_request intent's words, flow 7): «محتاجة إيه؟» first.
        if ($texts !== [] && ! $this->flows->isActive($c) && BotFlow::active(self::MAIN_MENU) !== null && $this->human->isHumanRequest(implode("\n", $texts))) {
            $this->human->requested($c, $burst, implode("\n", $texts));

            return $this->record($c, $texts, $started, 'button');
        }

        // 3. Greetings or a menu word open the main menu (the greeting script first on the first reply).
        if ($texts !== [] && $this->wantsMenu($texts) && BotFlow::active(self::MAIN_MENU) !== null) {
            if ($this->isFirstBotReply($c) && ($greeting = $this->prompter->script('greeting')) !== null) {
                $this->send($c, $greeting);
            }

            $this->flows->start($c, self::MAIN_MENU);

            return $this->record($c, $texts, $started, 'menu');
        }

        return null;
    }

    /** runPayload, plus the main menu after a "no" to an offer made outside a flow (never silence). */
    private function runPayload(Conversation $c, string $payload): bool
    {
        $offerOutsideFlow = $payload === 'no' && FlowState::confirm($c) !== null && ! $this->flows->isActive($c);

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
