<?php

namespace App\Bot\Flows;

use App\Bot\Agent\StoreAgent;
use App\Bot\Catalog\ProductBrowser;
use App\Bot\Flow\BurstPolicy;
use App\Bot\Flows\Sandbox\SandboxMode;
use App\Bot\Flows\Steps\FlowStep;
use App\Bot\Flows\Steps\OrderStep;
use App\Bot\Flows\Steps\StepOutcome;
use App\Bot\Language\BotTranslator;
use App\Bot\Language\ConversationLanguage;
use App\Channels\Cards\OutboundCards;
use App\Enums\AttachmentType;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Inbox\OutboundService;
use App\Inbox\WindowClosedException;
use App\Models\BotLearningNote;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Message;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Walks a customer through a guided flow stored in `bot_flows` (design §3).
 * State lives in `bot_state.flow`; every prompt is the step text as written,
 * sent with its buttons. A step type maps to an entry handler (`enter`) and
 * an answer resolver (`resolveTyped` / `answerValue`). Types with their own
 * rules (order, photo, branch, branches_list, status, record_case) live in
 * `Steps\*` via FlowSteps and report back a StepOutcome.
 *
 * A choice/status option may carry an `action` instead of a `next` step
 * (2026-09-19): `flow:<key>` starts that flow with the verified order carried
 * over (OrderStep::carried — its `order` step is then skipped), `menu:<key>`
 * and `handover` run as the menu buttons do.
 */
class FlowEngine
{
    /** Steps run back-to-back (scripts) before the engine gives up on a cycle. */
    private const MAX_CHAINED_STEPS = 25;

    /** The products menu (the owner's flow 6): its scripts come with the store-link button. */
    public const PRODUCTS_FLOW = 'products';

    /** Kept here so callers outside the Flows namespace do not reach for ConversationRouter. */
    public const MAIN_MENU_FLOW = ConversationRouter::MAIN_MENU;

    public const STORE_LINK_SCRIPTS = ['availability', 'size', 'delivery_time', 'payment_info'];

    public const STORE_BUTTON = '🛍️ تسوقي من الموقع';

    /** Types whose prompt waits for an answer handled by this engine. */
    private const ANSWERABLE = ['menu', 'choice', 'text', 'name', 'phone', 'summary'];

    /** @var list<string> the current burst's texts (handover summary) */
    private array $turnTexts = [];

    /** @var array<string, array|null> valid definitions read from the FlowDefinitionSource (one source per instance) */
    private array $definitions = [];

    /** Base send delay for the messages of the current start()/repromptCurrent() call (0 = now). */
    private int $sendDelayMs = 0;

    /** How many delayed messages the current call already queued (keeps them in order). */
    private int $delayedSends = 0;

    /** Spacing between consecutive delayed messages of one call. */
    private const DELAYED_SEND_STEP_MS = 300;

    /** A line to put above the next prompt this call sends (start()'s $lead), used once. */
    private ?string $pendingLead = null;

    public function __construct(
        private readonly OutboundService $outbound,
        private readonly ButtonMatcher $buttons,
        private readonly FlowPrompter $prompter,
        private readonly FlowAnswerResolver $resolver,
        private readonly FlowAnswerInterpreter $interpreter,
        private readonly FlowSteps $steps,
        private readonly FlowDefinitionSource $source,
        private readonly FlowHandover $handovers,
        private readonly HumanHandover $human,
        private readonly GreetingMirror $mirrors,
        private readonly BurstPolicy $social,
        private readonly ConversationLanguage $language,
        private readonly BotTranslator $translator,
    ) {}

    /** A flow is waiting for her, or the «كلم موظف» topic question is (HumanHandover). */
    public function isActive(Conversation $c): bool
    {
        return FlowState::flow($c) !== null || HumanHandover::pending($c);
    }

    /**
     * @param  int  $delayMs  queue the flow's messages after this delay (a reply part still on its way)
     * @param  string|null  $lead  a line put above the flow's first question, on the same message
     *                             (design 2026-09-21 §6.6: «أقدر أساعدك في 👇» + the menu)
     */
    public function start(Conversation $c, string $flowKey, array $prefill = [], int $delayMs = 0, ?string $lead = null): void
    {
        $this->pendingLead = $lead !== null && trim($lead) !== '' ? trim($lead) : null;

        try {
            $this->withDelay($delayMs, fn () => $this->begin($c, $flowKey, $prefill));
        } finally {
            $this->pendingLead = null;
        }
    }

    public function handle(Conversation $c, Collection $burst): FlowResult
    {
        if ($burst->isNotEmpty() && HumanHandover::pending($c)) {
            // Her answer to «محتاجة إيه؟» (or the skip button): the topic, then the team.
            $payload = $burst->reverse()->map(fn (Message $m) => $this->buttons->match($c, $m))->first(fn ($p) => $p !== null);
            $this->human->answer($c, $burst, $payload);

            return new FlowResult(true, exited: true);
        }

        $state = FlowState::flow($c);

        if ($state === null || $burst->isEmpty()) {
            return new FlowResult(false);
        }

        $this->turnTexts = $burst->map(fn (Message $m) => trim((string) $m->body))->filter()->values()->all();
        $text = implode("\n", $this->turnTexts);
        $step = $this->step($state['key'], $state['step']);

        // §6.5: she went quiet and came back much later — never carry on as if nothing happened.
        if ($step !== null && $this->offerResume($c, $state, $burst)) {
            return new FlowResult(true);
        }

        if ($step === null) {
            FlowState::clear($c);

            return new FlowResult(false, exited: true);
        }

        $payload = $burst->reverse()->map(fn (Message $m) => $this->buttons->match($c, $m))->first(fn ($p) => $p !== null);

        if ($payload !== null) {
            if (str_starts_with($payload, "step:{$state['key']}:{$state['step']}:")) {
                if ($this->runPayload($c, $payload)) {
                    return new FlowResult(true);
                }
            } elseif (! str_starts_with($payload, 'step:') && $this->runPayload($c, $payload)) {
                $navigates = $step['type'] !== 'menu' && preg_match('/^(menu:|flow:|handover$)/', $payload) === 1;

                return new FlowResult(true, exited: $navigates);
            }

            // A stale tap (an old step's button, or yes/no with no pending offer): its body is only the
            // button title, so never read it as a typed answer — re-ask the waiting step, no retry counted.
            if (str_starts_with($payload, 'step:') || in_array($payload, ['yes', 'no'], true)) {
                // Never word for word (design 2026-09-21 §3): the waiting step comes back
                // under «إحنا خلصنا الخطوة دي فعلًا 🌸», so she can see why it changed.
                $this->repromptCurrent($c, 0, $this->prompter->script('flow_stale_tap'));

                return new FlowResult(true);
            }
        }

        if (FlowState::confirm($c) !== null) {
            $yesNo = $this->resolver->yesNo($text);

            if ($yesNo !== null && $this->runPayload($c, $yesNo)) {
                return new FlowResult(true);
            }

            FlowState::setConfirm($c, null);
        }

        return $this->resolveText($c, $state, $step, $text, $burst);
    }

    /**
     * Payloads: menu:<key>, flow:<key>, script:<key>, handover, handover:now,
     * handover:not_understood, resume:continue, resume:restart, yes, no,
     * step:<flow>:<step>:<value>, products:featured, products:type:<type>, product:<id>.
     */
    public function runPayload(Conversation $c, string $payload): bool
    {
        $payload = trim($payload);
        [$kind, $rest] = array_pad(explode(':', $payload, 2), 2, '');

        switch ($kind) {
            case 'handover':
                // «حوّليني على طول» (the topic question's skip button) hands over as it is.
                if ($rest === 'now') {
                    $this->human->handover($c, null, $this->customerText($c));

                    return true;
                }

                // §3: two misses on the same step — the reason the team sees says so.
                if ($rest === 'not_understood') {
                    $this->handover($c, 'not_understood', FlowState::flow($c)['data'] ?? []);

                    return true;
                }

                $this->requestHuman($c);

                return true;
            case 'resume':
                return $this->answerResume($c, $rest);
            case 'yes':
            case 'no':
                return $this->answerConfirm($c, $kind);
            case 'menu':
            case 'flow':
                return $rest !== '' && $this->begin($c, $rest);
            case 'products':
                return app(ProductBrowser::class)->browse($c, $rest);
            case 'product':
                return app(ProductBrowser::class)->detail($c, $rest);
            case 'script':
                // «الموديلات والأسعار» (2026-09-22): the catalog as picture cards; its script only when the catalog is empty.
                if ($rest === 'availability' && app(ProductBrowser::class)->browse($c, 'featured')) {
                    return true;
                }

                $body = $this->prompter->script($rest, FlowState::flow($c)['data'] ?? []);

                if ($rest === '' || $body === null) {
                    return false;
                }

                $this->send($c, $body, [FlowPrompter::MAIN_MENU_BUTTON], $this->storeLinkCards($c, $rest));

                return true;
            case 'step':
                return $this->answerStepPayload($c, $rest);
            default:
                return false;
        }
    }

    /** Re-sends the waiting step's prompt with its buttons (after an agent answered a question). */
    public function repromptCurrent(Conversation $c, int $delayMs = 0, ?string $lead = null): void
    {
        $state = FlowState::flow($c);
        $step = $state !== null ? $this->step($state['key'], $state['step']) : null;

        if ($step !== null && ! in_array($step['type'] ?? null, ['script', 'handover', 'end'], true)) {
            $this->withDelay($delayMs, fn () => $this->sendPrompt($c, $state, $step, $lead));
        }
    }

    /**
     * Design 2026-09-21 §6.1: a question was answered in the middle of a flow. She is always
     * brought back — «نرجع لطلب المرتجع 🌸» on the same message as the step's question and its
     * buttons, so nothing is ever repeated word for word. After `crm.bot.flow_max_detours`
     * questions in one flow a person is offered instead.
     *
     * @return bool whether the flow is still running afterwards
     */
    public function returnToFlow(Conversation $c, int $delayMs = 0): bool
    {
        $state = FlowState::flow($c);

        if ($state === null) {
            return false;
        }

        $state['detours']++;
        FlowState::put($c, $state);

        if ($state['detours'] > max(0, (int) config('crm.bot.flow_max_detours', 2))) {
            $offer = $this->prompter->script('flow_too_many_detours') ?? 'عشان نخلص طلب حضرتك صح، تحبي أوصلك لموظف يساعدك؟';
            $this->withDelay($delayMs, fn () => $this->send($c, $offer, [FlowLabels::YES_BUTTON, FlowLabels::NO_BUTTON]));
            FlowState::setConfirm($c, 'handover_offer');

            return true;
        }

        $lead = $this->prompter->script('flow_back_to') ?? 'نرجع لـ{flow_label} 🌸';
        $this->repromptCurrent($c, $delayMs, str_replace('{flow_label}', FlowLabels::of($state['key']), $lead));

        return true;
    }

    private function withDelay(int $delayMs, callable $send): void
    {
        [$previousDelay, $previousCount] = [$this->sendDelayMs, $this->delayedSends];
        $this->sendDelayMs = max(0, $delayMs);
        $this->delayedSends = 0;

        try {
            $send();
        } finally {
            [$this->sendDelayMs, $this->delayedSends] = [$previousDelay, $previousCount];
        }
    }

    private function begin(Conversation $c, string $flowKey, array $prefill = []): bool
    {
        $def = $this->definition($flowKey);

        if ($def === null) {
            return false;
        }

        FlowState::setConfirm($c, null);
        FlowState::put($c, ['key' => $flowKey, 'step' => (string) $def['start'], 'data' => $prefill, 'retries' => 0, 'detours' => 0, 'started_at' => now()->toIso8601String()]);
        $this->run($c, (string) $def['start']);

        return true;
    }

    /** Enters $stepKey and keeps going through non-waiting steps. */
    private function run(Conversation $c, string $stepKey): void
    {
        for ($i = 0; $i < self::MAX_CHAINED_STEPS; $i++) {
            $state = FlowState::flow($c);
            $step = $state !== null && $stepKey !== 'end' ? $this->step($state['key'], $stepKey) : null;

            if ($step === null) {
                FlowState::clear($c);

                return;
            }

            $state['step'] = $stepKey;
            FlowState::put($c, $state);

            $next = $this->enter($c, $state, $step);

            if ($next === null) {
                return;
            }

            $stepKey = $next;
        }

        Log::warning('flow.step_cycle', ['conversation_id' => $c->id, 'flow' => FlowState::flow($c)['key'] ?? null]);
        FlowState::clear($c);
    }

    /** Runs a step on entry; returns the step to continue with, or null to wait/stop. */
    private function enter(Conversation $c, array $state, array $step): ?string
    {
        if (($handler = $this->steps->for($step['type'] ?? null)) !== null) {
            return $this->apply($c, $state, $step, $handler->enter($c, $state, $step));
        }

        switch ($step['type'] ?? null) {
            case 'script':
                // The step's own text wins over its script (2026-09-19, like record_case).
                $own = trim((string) ($step['text'] ?? ''));
                $body = $own !== '' ? $this->prompter->renderText($own, $state['data']) : $this->prompter->script((string) ($step['script'] ?? ''), $state['data']);

                if ($body !== null) {
                    $this->send($c, $body);
                }

                return $this->nextFor($step, $state['data']);
            case 'handover':
                $this->handover($c, 'flow', $state['data']);

                return null;
            case 'end':
                FlowState::clear($c);

                return null;
            default:
                // menu, choice, text, name, phone, summary: send the prompt and wait.
                $this->sendPrompt($c, $state, $step);

                return null;
        }
    }

    private function resolveText(Conversation $c, array $state, array $step, string $text, Collection $burst): FlowResult
    {
        $type = (string) $step['type'];

        // Exit words win over option synonyms (e.g. "القائمة" on a menu with a "القائمة الرئيسية" option).
        if (($exit = $this->resolver->exitWord($text)) !== null) {
            return $this->exit($c, $exit === 'menu');
        }

        if (($handler = $this->steps->for($type)) !== null) {
            return $this->resolveWithHandler($c, $state, $step, $handler, $text, $burst);
        }

        // A question ("الشحن بياخد قد ايه؟") skips title/synonym matching and goes to the interpreter.
        $isQuestion = $this->resolver->isQuestion($text);

        if (! $isQuestion && $type === 'menu' && ($o = $this->matchOption($c, $this->prompter->visibleMenuOptions($step), $text)) !== null) {
            return new FlowResult($this->runPayload($c, (string) $o['action']));
        }

        if (! $isQuestion && $type === 'choice' && ($o = $this->matchOption($c, $this->prompter->choiceOptions($step, $state['data']), $text)) !== null) {
            $this->answer($c, $state, $step, (string) $o['value'], (string) $o['title'], $o);

            return new FlowResult(true);
        }

        if (! $isQuestion && $type === 'summary' && ($choice = $this->resolver->summaryChoice($text)) !== null) {
            $this->answerValue($c, $state, $step, $choice);

            return new FlowResult(true);
        }

        // A choice that also takes her own words (2026-09-19, «كانت الزيارة إمتى؟» → "الخميس اللي فات").
        if (! $isQuestion && $type === 'choice' && ($step['allow_text'] ?? false) === true && self::meaningful($text)) {
            $this->answer($c, $state, $step, mb_substr(trim($text), 0, 500), mb_substr(trim($text), 0, 500));

            return new FlowResult(true);
        }

        // A text step with `photos: true` (the complaint's description) keeps the burst's photos too.
        if ($type === 'text' && ($step['photos'] ?? false) === true && filled($step['field'] ?? null)) {
            $photos = $this->photoIds($burst);

            if ($photos !== [] || self::meaningful($text)) {
                $state['data'][$step['field'].'_photo'] = $photos !== [] ? $photos : null;
                $this->answer($c, $state, $step, trim($text));

                return new FlowResult(true);
            }
        }

        if (($value = $this->resolveTyped($type, $text)) !== null) {
            $this->answer($c, $state, $step, $value);

            return new FlowResult(true);
        }

        // §6: she is not answering the step — a person, another flow, or small talk.
        if (($off = $this->offFlow($c, $state, $step, $text)) !== null) {
            return $off;
        }

        // The interpreter only sees the options she was offered (no refund for exchange-only items).
        $asked = $type === 'choice' ? ['options' => $this->prompter->choiceOptions($step, $state['data'])] + $step : $step;
        $answer = $text === '' ? FlowAnswer::unknown() : $this->interpret($c, $asked, $text, $burst);

        return match ($answer->kind) {
            'question' => new FlowResult(true, question: $text),
            'exit' => $this->exit($c, $this->resolver->mentionsMenu($text)),
            'answer' => in_array($type, self::ANSWERABLE, true) && $this->answerValue($c, $state, $step, (string) $answer->value)
                ? new FlowResult(true)
                : $this->retry($c, $state, $step),
            // A reply written as a question that the model could not map to an option is
            // still a question (§6.1): it is answered and she is brought back, not re-asked.
            default => $isQuestion ? new FlowResult(true, question: $text) : $this->retry($c, $state, $step),
        };
    }

    /** Handler steps: own rule, then the interpreter (question/exit), then the handler's unresolved rule. */
    private function resolveWithHandler(Conversation $c, array $state, array $step, FlowStep $handler, string $text, Collection $burst): FlowResult
    {
        if (($outcome = $handler->answer($c, $state, $step, $text, $burst)) !== null) {
            $this->proceed($c, $state, $step, $outcome);

            return new FlowResult(true);
        }

        // §6: a person, another flow, or small talk — before the step's own "I did not get that".
        if (($off = $this->offFlow($c, $state, $step, $text)) !== null) {
            return $off;
        }

        $answer = $text === '' ? FlowAnswer::unknown() : $this->interpret($c, $step, $text, $burst);

        // §6.1 and §3: a step with its own validation (order, photo, phone, branch…) answers
        // one question and comes back, but keeps its own two-attempt rule — so «مش معايا صورة»
        // gets an answer once and then the step moves on instead of asking forever.
        if ($answer->kind === 'question' || ($answer->kind === 'unknown' && $this->resolver->isQuestion($text))) {
            if ((int) $state['retries'] < 1) {
                $state['retries'] = 1;
                FlowState::put($c, $state);

                return new FlowResult(true, question: $text);
            }

            // 2026-09-22 (seen in the live test): a second thing that the interpreter is sure is not an
            // answer — «الفلوس اتخصمت مرتين ومحدش بيرد!!» at the order step — was kept as the order
            // number. With the store agent on it is answered (or handed over) instead; the detour cap
            // of returnToFlow still stops her going round forever.
            if ($answer->kind === 'question' && StoreAgent::enabled()) {
                return new FlowResult(true, question: $text);
            }
        }

        if ($answer->kind === 'exit') {
            return $this->exit($c, $this->resolver->mentionsMenu($text));
        }

        $outcome = $answer->kind === 'answer' && filled($answer->value) && $answer->value !== $text
            ? $handler->answer($c, $state, $step, (string) $answer->value, $burst)
            : null;

        $this->proceed($c, $state, $step, $outcome ?? $handler->unresolved($c, $state, $step, $text));

        return new FlowResult(true);
    }

    /**
     * Design 2026-09-21 §6: what she wrote is not an answer to the waiting step.
     *
     *   §6.4 she asks for a person  → the team, with what the flow has collected so far;
     *   §6.2 she asks for another flow → «تحبي نسيب … ونتابع …؟» before anything is dropped;
     *   §6.3 thanks or a greeting   → one polite line and the same step again, no failure counted.
     *
     * Returns null when it really is (a bad) answer to the step, so the normal path runs.
     */
    private function offFlow(Conversation $c, array $state, array $step, string $text): ?FlowResult
    {
        if (trim($text) === '') {
            return null;
        }

        if ($this->human->isHumanRequest($text)) {
            $this->handover($c, 'human_request', $state['data']);

            return new FlowResult(true, exited: true);
        }

        if (($target = $this->otherFlow($c, $state, $text)) !== null) {
            $this->offerSwitch($c, $state, $target);

            return new FlowResult(true);
        }

        if (($social = $this->social->socialIntent($text)) !== null) {
            $line = $social === 'greeting'
                ? ($this->mirrors->line($text) ?? $this->prompter->script('greeting_mirror_hi'))
                : $this->prompter->script('flow_thanks');

            if ($line !== null) {
                $this->send($c, $line);
            }

            // Not a failure: her retry count is untouched and the step is simply asked again.
            $this->sendPrompt($c, $state, $step);

            return new FlowResult(true);
        }

        return null;
    }

    /**
     * §6.2: the main-menu option her words point at, when it is a different flow from the
     * one running. Only whole main-menu options count, so «استبدال» inside the return flow
     * (its own option) never looks like a switch.
     */
    private function otherFlow(Conversation $c, array $state, string $text): ?string
    {
        if ($state['key'] === ConversationRouter::MAIN_MENU) {
            return null;
        }

        $menu = $this->step(ConversationRouter::MAIN_MENU, (string) ($this->definition(ConversationRouter::MAIN_MENU)['start'] ?? ''));

        if ($menu === null) {
            return null;
        }

        // The same reading as a menu answer: exact, then the strict fuzzy match, so «I want to
        // track my order instead» is recognised as another flow and not read as a question.
        $option = $this->matchOption($c, $this->prompter->visibleMenuOptions($menu), $text);
        $action = (string) ($option['action'] ?? '');

        if (! preg_match('/^(?:flow|menu):(.+)$/', $action, $m)) {
            return null;
        }

        return $m[1] !== $state['key'] && $m[1] !== ConversationRouter::MAIN_MENU && $this->definition($m[1]) !== null ? $m[1] : null;
    }

    private function offerSwitch(Conversation $c, array $state, string $target): void
    {
        $body = $this->prompter->script('flow_switch_offer') ?? 'تحبي نسيب {from_label} ونتابع {to_label}؟';
        $body = strtr($body, ['{from_label}' => FlowLabels::of($state['key']), '{to_label}' => FlowLabels::of($target)]);

        $this->send($c, $body, [FlowLabels::YES_BUTTON, FlowLabels::KEEP_BUTTON]);
        FlowState::setConfirm($c, 'switch:'.$target);
    }

    /** Applies a handler outcome in an answer context and runs the next step when it continues. */
    private function proceed(Conversation $c, array $state, array $step, StepOutcome $outcome): void
    {
        if (($next = $this->apply($c, $state, $step, $outcome)) !== null) {
            $this->run($c, $next);
        }
    }

    /** Sends the outcome's messages, saves its data, and returns the next step key when it continues. */
    private function apply(Conversation $c, array $state, array $step, StepOutcome $outcome): ?string
    {
        foreach ($outcome->messages as $message) {
            $this->send($c, (string) $message['text'], $message['buttons'] ?? [], $message['cards'] ?? null);
        }

        foreach ($outcome->data as $key => $value) {
            if ($value === null) {
                unset($state['data'][$key]);
            } else {
                $state['data'][$key] = $value;
            }
        }

        switch ($outcome->kind) {
            case StepOutcome::CONTINUE:
                $state['retries'] = 0;
                FlowState::put($c, $state);
                FlowState::setConfirm($c, null);

                return $outcome->next ?? $this->nextFor($step, $state['data']);
            case StepOutcome::JUMP:
                $state['retries'] = 0;
                FlowState::put($c, $state);
                FlowState::setConfirm($c, null);
                $this->jump($c, (string) $outcome->action);

                return null;
            case StepOutcome::RETRY:
                FlowState::put($c, $state);
                $this->retry($c, $state, $step);

                return null;
            case StepOutcome::HANDOVER:
                FlowState::put($c, $state);
                $category = (string) $outcome->handoverCategory;
                $this->handover($c, $category, $state['data'], $category);

                return null;
            case StepOutcome::END:
                FlowState::clear($c);

                return null;
            default: // wait
                $state['retries'] = $outcome->retries ?? $state['retries'];
                FlowState::put($c, $state);

                return null;
        }
    }

    /** Regex/heuristic answers per step type. */
    private function resolveTyped(string $type, string $text): ?string
    {
        return match ($type) {
            'phone' => $this->resolver->phone($text),
            'name' => $this->resolver->name($text),
            // Only an emoji or a dot is not an answer (the cancel reason is required, 2026-09-19).
            'text' => self::meaningful($text) ? $text : null,
            default => null,
        };
    }

    /** At least one letter or digit: "🙏" or "." alone does not answer a question. */
    public static function meaningful(string $text): bool
    {
        return preg_match('/[\p{L}\p{N}]/u', $text) === 1;
    }

    /** @return list<int|string> the burst's image attachment ids (`legacy` for an old-style image only) */
    private function photoIds(Collection $burst): array
    {
        $ids = [];
        $legacy = false;

        foreach ($burst as $m) {
            /** @var Message $m */
            if ($m->exists) {
                array_push($ids, ...$m->mediaAttachments()->where('type', AttachmentType::Image->value)->pluck('id')->map(fn ($id) => (int) $id)->all());
            }

            $legacy = $legacy || collect((array) $m->attachments)->contains(fn ($a) => is_array($a) && ($a['type'] ?? null) === 'image');
        }

        return $ids !== [] ? $ids : ($legacy ? ['legacy'] : []);
    }

    /** Applies a resolved value (button payload value or interpreter answer) to the waiting step. */
    private function answerValue(Conversation $c, array $state, array $step, string $value): bool
    {
        if (($handler = $this->steps->for($step['type'] ?? null)) !== null) {
            $outcome = $handler->payload($c, $state, $step, $value);

            if ($outcome !== null) {
                $this->proceed($c, $state, $step, $outcome);
            }

            return $outcome !== null;
        }

        switch ($step['type'] ?? null) {
            case 'choice':
                $option = collect($this->prompter->choiceOptions($step, $state['data']))->first(fn ($o) => (string) ($o['value'] ?? '') === $value);

                if ($option === null) {
                    return false;
                }

                $this->answer($c, $state, $step, $value, (string) $option['title'], $option);

                return true;
            case 'menu':
                return in_array($value, array_column($this->prompter->visibleMenuOptions($step), 'action'), true) && $this->runPayload($c, $value);
            case 'summary':
                if ($value === 'edit') {
                    FlowState::put($c, ['retries' => 0] + $state);
                    $this->run($c, (string) $this->definition($state['key'])['start']);

                    return true;
                }

                if ($value !== 'confirm') {
                    return false;
                }

                $this->answer($c, $state, $step, $value);

                return true;
            case 'text':
            case 'name':
            case 'phone':
                if (! self::meaningful($value)) {
                    return false;
                }

                $this->answer($c, $state, $step, trim($value));

                return true;
            default:
                return false;
        }
    }

    private function answerStepPayload(Conversation $c, string $rest): bool
    {
        [$flowKey, $stepKey, $value] = array_pad(explode(':', $rest, 3), 3, '');
        $state = FlowState::flow($c);

        if ($state === null || $state['key'] !== $flowKey || $state['step'] !== $stepKey || $value === '') {
            return false;
        }

        $step = $this->step($flowKey, $stepKey);

        return $step !== null && $this->answerValue($c, $state, $step, $value);
    }

    /**
     * Saves the answer in `data[field]` and moves on: to the matched choice
     * option's own `next` when it has one (ruling R-F8), else branches/`next`.
     *
     * @param  array<string, mixed>|null  $option  the choice option the answer matched
     */
    private function answer(Conversation $c, array $state, array $step, string $value, ?string $title = null, ?array $option = null): void
    {
        if (filled($step['field'] ?? null)) {
            $state['data'][$step['field']] = $value;

            if ($title !== null) {
                $state['data'][$step['field'].'_title'] = $title;
            }
        }

        $state['retries'] = 0;
        FlowState::put($c, $state);
        FlowState::setConfirm($c, null);

        $optionNext = $option['next'] ?? null;
        $action = $option['action'] ?? null;

        if (! (is_string($optionNext) && $optionNext !== '') && is_string($action) && $action !== '') {
            $this->jump($c, $action);

            return;
        }

        $this->run($c, is_string($optionNext) && $optionNext !== '' ? $optionNext : $this->nextFor($step, $state['data']));
    }

    /**
     * An option's `action`: `flow:<key>` starts that flow carrying the verified order (no
     * re-asking the number), anything else runs as a button payload. A target that cannot
     * run (a missing or inactive flow) hands her to a person instead of leaving her in silence.
     */
    private function jump(Conversation $c, string $action): void
    {
        $action = trim($action);

        if (str_starts_with($action, 'flow:')) {
            $key = substr($action, 5);

            if ($key !== '' && $this->begin($c, $key, OrderStep::carried(FlowState::flow($c)['data'] ?? []))) {
                return;
            }
        } elseif ($this->runPayload($c, $action)) {
            return;
        }

        Log::warning('flow.jump_failed', ['conversation_id' => $c->id, 'action' => $action]);
        $this->handoverToHuman($c);
    }

    /** First branch whose data[field] is in `in`, else `next` (default "end"). */
    private function nextFor(array $step, array $data): string
    {
        foreach ($step['branches'] ?? [] as $branch) {
            $v = $data[$branch['field'] ?? ''] ?? null;

            if (is_scalar($v) && in_array((string) $v, array_map('strval', $branch['in'] ?? []), true)) {
                return (string) $branch['next'];
            }
        }

        return (string) ($step['next'] ?? 'end');
    }

    /**
     * She did not answer the step (design 2026-09-21 §3). The same question is never asked
     * again word for word:
     *
     *   first miss  → ONE message: «معلش مش واضحة ليا 🙏 اختاري من دول:» carrying the step's
     *                 own buttons (a step with no buttons gets the question under the apology);
     *   second miss → the apology plus [كلم موظف] [القائمة الرئيسية], and an `unanswered`
     *                 learning note so the owner sees what the bot could not read.
     */
    private function retry(Conversation $c, array $state, array $step): FlowResult
    {
        $state['retries']++;
        FlowState::put($c, $state);

        if ($state['retries'] < 2) {
            $prompt = $this->promptFor($state, $step);
            $apology = $this->prompter->script('flow_retry') ?? 'معلش مش واضحة ليا 🙏 اختاري من دول:';
            $text = $prompt['buttons'] === [] ? $apology."\n".$prompt['text'] : $apology;

            $this->send($c, $text, $prompt['buttons']);

            return new FlowResult(true);
        }

        $this->noteNotUnderstood($c, $state, $step);

        $offer = $this->prompter->script('flow_not_understood') ?? 'معلش، لسه مش قادرة أفهم قصدك 🙏 تحبي أوصلك لموظف يساعدك؟';
        $this->send($c, $offer, [FlowLabels::AGENT_BUTTON, FlowLabels::MENU_BUTTON]);
        FlowState::setConfirm($c, 'handover_offer');

        return new FlowResult(true);
    }

    /**
     * A learning note (kind `unanswered`) for a step that failed twice, so the owner reads
     * in Settings → تعلم البوت exactly what she wrote and which step could not read it.
     */
    private function noteNotUnderstood(Conversation $c, array $state, array $step): void
    {
        if (SandboxMode::active() || ! $c->exists) {
            return;
        }

        rescue(fn () => BotLearningNote::create([
            'conversation_id' => $c->id,
            'channel_account_id' => $c->channel_account_id,
            'source' => $c->is_test ? BotLearningNote::SOURCE_TEST : BotLearningNote::SOURCE_LIVE,
            'last_message_id' => $c->messages()->where('direction', MessageDirection::In->value)->max('id'),
            'notes' => [[
                'kind' => 'unanswered',
                'summary' => 'البوت مفهمش ردها مرتين على خطوة '.$state['key'].'.'.$state['step'].' ('.trim((string) ($step['text'] ?? '')).')',
                'quote' => mb_substr($this->customerText($c), 0, 200),
            ]],
            'model' => null,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_usd' => 0,
        ]), null, report: false);
    }

    /** yes/no to a pending offer: the handover offer (§3) or the flow switch (§6.2). */
    private function answerConfirm(Conversation $c, string $answer): bool
    {
        $confirm = FlowState::confirm($c);

        // «نكمل» / «ابدأ من جديد» typed as "أيوه" / "لأ" (§6.5).
        if ($confirm === 'resume') {
            return $this->answerResume($c, $answer === 'no' ? 'restart' : 'continue');
        }

        if ($confirm !== null && str_starts_with($confirm, 'switch:')) {
            FlowState::setConfirm($c, null);

            if ($answer === 'no') {
                $this->repromptCurrent($c);

                return true;
            }

            // §6.2: what she had already given is kept on the conversation before the flow is dropped.
            $this->noteAbandoned($c);

            return $this->begin($c, substr($confirm, 7));
        }

        if ($confirm !== 'handover_offer') {
            return false;
        }

        FlowState::setConfirm($c, null);

        if ($answer === 'yes') {
            $this->handoverToHuman($c);

            return true;
        }

        if (($state = FlowState::flow($c)) !== null) {
            $state['retries'] = 0;
            FlowState::put($c, $state);
            $this->repromptCurrent($c);
        }

        return true;
    }

    /** §6.2: the partial data of a flow she chose to leave, noted for the team. */
    private function noteAbandoned(Conversation $c): void
    {
        $state = FlowState::flow($c);
        $lines = $state !== null ? $this->prompter->summaryLines($state['data']) : [];

        if ($state === null || $lines === [] || SandboxMode::active() || ! $c->exists) {
            return;
        }

        rescue(fn () => ConversationNote::create([
            'conversation_id' => $c->id,
            'user_id' => null,
            'body' => 'سابت «'.FlowLabels::of($state['key']).'» في النص، واللي جمعناه: '."\n".implode("\n", $lines),
            'mentions' => [],
        ]), null, report: false);
    }

    /**
     * §6.5: a flow she left hanging longer than `crm.bot.flow_resume_minutes` asks
     * «لسه فاكرين طلبك 🌸 تحبي نكمل من حيث ما وقفنا؟» [نكمل] [ابدأ من جديد] before anything
     * she now writes is read as an answer to a step she may not even remember.
     */
    private function offerResume(Conversation $c, array $state, Collection $burst): bool
    {
        $minutes = (int) config('crm.bot.flow_resume_minutes', 30);
        $idle = FlowState::idleMinutes($c);

        if ($minutes <= 0 || $idle === null || $idle < $minutes || FlowState::confirm($c) !== null) {
            return false;
        }

        // A tap on one of the buttons she was already offered is an answer, not a comeback.
        $payload = $burst->reverse()->map(fn (Message $m) => $this->buttons->match($c, $m))->first(fn ($p) => $p !== null);

        if ($payload !== null) {
            FlowState::put($c, $state);

            return false;
        }

        $offer = $this->prompter->script('flow_resume_offer') ?? 'لسه فاكرين طلبك 🌸 تحبي نكمل من حيث ما وقفنا؟';
        $this->send($c, $offer, [FlowLabels::RESUME_BUTTON, FlowLabels::RESTART_BUTTON]);
        FlowState::setConfirm($c, 'resume');
        FlowState::put($c, $state);

        return true;
    }

    private function answerResume(Conversation $c, string $choice): bool
    {
        if (FlowState::confirm($c) !== 'resume' || ($state = FlowState::flow($c)) === null) {
            return false;
        }

        FlowState::setConfirm($c, null);

        if ($choice === 'restart') {
            return $this->begin($c, $state['key']);
        }

        $state['retries'] = 0;
        FlowState::put($c, $state);
        $this->repromptCurrent($c);

        return true;
    }

    private function exit(Conversation $c, bool $showMenu): FlowResult
    {
        FlowState::clear($c);

        if ($showMenu && $this->begin($c, 'main_menu')) {
            return new FlowResult(true, exited: true);
        }

        return new FlowResult(false, exited: true);
    }

    private function handoverToHuman(Conversation $c): void
    {
        $this->handover($c, 'human_request', FlowState::flow($c)['data'] ?? []);
    }

    /**
     * She asked for a person (the «كلم موظف» button, 2026-09-19): from the main menu, or with no
     * flow going, the bot first asks what she needs (HumanHandover::askTopic). Inside another flow
     * what she was doing is the context already, so she goes straight to the team.
     */
    private function requestHuman(Conversation $c): void
    {
        $state = FlowState::flow($c);

        if ($state === null || $state['key'] === ConversationRouter::MAIN_MENU) {
            $this->human->askTopic($c, $this->customerText($c), $this->sendDelayMs);

            return;
        }

        $this->handoverToHuman($c);
    }

    /** Every flow handover: the working-hours reply, then the team (HumanHandover). */
    private function handover(Conversation $c, string $reason, array $data, string $category = 'human_request'): void
    {
        $this->human->handover($c, null, $this->customerText($c), $reason, $category, $this->prompter->summaryLines($data));

        FlowState::clear($c);
    }

    private function customerText(Conversation $c): string
    {
        return $this->turnTexts !== []
            ? implode("\n", $this->turnTexts)
            : (string) $c->messages()->where('direction', MessageDirection::In->value)->latest('id')->value('body');
    }

    /**
     * The store-link button under a script sent from the products menu (the owner's flow 6,
     * 2026-09-19): «🛍️ تسوقي من الموقع» → bot_settings.store_url.
     */
    private function storeLinkCards(Conversation $c, string $scriptKey): ?array
    {
        if ((FlowState::flow($c)['key'] ?? null) !== self::PRODUCTS_FLOW || ! in_array($scriptKey, self::STORE_LINK_SCRIPTS, true)) {
            return null;
        }

        return OutboundCards::button([OutboundCards::webUrl(self::STORE_BUTTON, BotSetting::current()->storeUrl())]);
    }

    private function sendPrompt(Conversation $c, array $state, array $step, ?string $lead = null): void
    {
        $lead ??= $this->pendingLead;
        $this->pendingLead = null;

        $prompt = $this->promptFor($state, $step);
        $text = $lead !== null && trim($lead) !== '' ? trim($lead)."\n".$prompt['text'] : $prompt['text'];

        $this->send($c, $text, $prompt['buttons']);
    }

    /** @return array{text:string, buttons:list<array{title:string, payload:string}>} */
    private function promptFor(array $state, array $step): array
    {
        $handler = $this->steps->for($step['type'] ?? null);

        return $handler !== null
            ? $handler->prompt($state, $step)
            : $this->prompter->prompt($state['key'], $state['step'], $step, $state['data']);
    }

    /**
     * Design 2026-09-21 §3: exact/synonym match (Arabic or English), then a fuzzy match, on
     * the option titles in both languages. The English titles are the ones she was actually
     * shown — read from the translation cache, so no model call is made here.
     *
     * @param  list<array<string, mixed>>  $options
     * @return array<string, mixed>|null
     */
    private function matchOption(Conversation $c, array $options, string $text): ?array
    {
        $options = $this->withEnglishTitles($c, $options);

        return $this->resolver->matchOption($options, $text) ?? $this->resolver->fuzzyOption($options, $text);
    }

    /**
     * Adds `synonyms_en` (the stored English title of each option) so a customer writing in
     * English matches the button she can see. Arabic conversations skip this entirely.
     *
     * @param  list<array<string, mixed>>  $options
     * @return list<array<string, mixed>>
     */
    private function withEnglishTitles(Conversation $c, array $options): array
    {
        if (! $this->language->isEnglish($c)) {
            return $options;
        }

        foreach ($options as $i => $option) {
            $english = $this->translator->cached((string) ($option['title'] ?? ''), $this->language->of($c));

            if ($english !== null) {
                $options[$i]['synonyms'] = [...array_map('strval', $option['synonyms'] ?? []), $english];
            }
        }

        return $options;
    }

    /**
     * @param  list<array{title:string, payload:string}>  $buttons
     * @param  array|null  $cards  rich cards (App\Channels\Cards\OutboundCards); $text is their plain-text fallback
     */
    private function send(Conversation $c, string $text, array $buttons = [], ?array $cards = null): void
    {
        if (trim($text) === '') {
            return;
        }

        try {
            $delay = $this->sendDelayMs > 0 ? $this->sendDelayMs + ($this->delayedSends++) * self::DELAYED_SEND_STEP_MS : 0;
            $this->outbound->sendBot($c, $text, $delay, true, $buttons, $cards);
        } catch (WindowClosedException) {
            Log::info('flow.window_closed', ['conversation_id' => $c->id]);
        }
    }

    private function interpret(Conversation $c, array $step, string $text, Collection $burst): FlowAnswer
    {
        if (($step['type'] ?? null) === 'menu') {
            $step['options'] = $this->prompter->visibleMenuOptions($step);
        }

        try {
            return $this->interpreter->interpret($step, $text, $this->history($c, $burst));
        } catch (Throwable $e) {
            Log::warning('flow.interpreter_failed', ['conversation_id' => $c->id, 'error' => $e->getMessage()]);

            return FlowAnswer::unknown();
        }
    }

    /** @return list<array{role:'customer'|'agent', text:string}> the last 12 messages before the burst */
    private function history(Conversation $c, Collection $burst): array
    {
        $firstId = $burst->first()?->id;

        return $c->messages()->when($firstId, fn ($q) => $q->where('id', '<', $firstId))
            ->orderByDesc('id')->limit(12)->get()->reverse()->values()
            ->map(fn (Message $m) => ['role' => $m->sender_type === SenderType::Customer ? 'customer' : 'agent', 'text' => (string) $m->body])
            ->all();
    }

    /**
     * She is only looking at a menu (the main menu, «الموديلات والأسعار»): nothing is being
     * collected, so a question here is a plain question — not a detour to count and come back from.
     */
    public function atMenu(Conversation $c): bool
    {
        $state = FlowState::flow($c);

        return $state !== null && ($this->step((string) $state['key'], (string) $state['step'])['type'] ?? null) === 'menu';
    }

    private function step(string $flowKey, string $stepKey): ?array
    {
        $step = $this->definition($flowKey)['steps'][$stepKey] ?? null;

        return is_array($step) && is_string($step['type'] ?? null) ? $step : null;
    }

    private function definition(string $flowKey): ?array
    {
        if (! array_key_exists($flowKey, $this->definitions)) {
            $def = $this->source->definition($flowKey);
            $this->definitions[$flowKey] = is_array($def) && is_array($def['steps'] ?? null) && isset($def['steps'][$def['start'] ?? '']) ? $def : null;
        }

        return $this->definitions[$flowKey];
    }
}
