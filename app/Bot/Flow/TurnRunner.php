<?php

namespace App\Bot\Flow;

use App\Bot\ArabicNormalizer;
use App\Bot\Flow\Orders\OrderLookup;
use App\Bot\Flow\Orders\OrderSnapshot;
use App\Bot\Flow\Orders\OrderStatusText;
use App\Bot\Flow\Scripts\LeVoileScripts;
use App\Bot\Flows\FlowEngine;
use App\Bot\Flows\FlowPrompter;
use App\Bot\Flows\FlowScripts;
use App\Bot\Flows\FlowState;
use App\Bot\HandoverSignals;
use App\Bot\Knowledge\KnowledgeBase;
use App\Enums\AttachmentType;
use App\Enums\Handler;
use App\Enums\SenderType;
use App\Inbox\OutboundService;
use App\Inbox\WindowClosedException;
use App\Models\BotFlow;
use App\Models\BotIntent;
use App\Models\BotRun;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SupportCase;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Throwable;

/**
 * One conversation turn over a whole burst (spec §2): understand → route →
 * act → compose → paced send → handover. Rules, hours, handover keywords and
 * the turn limit were already checked by BotEngine::handleTurn.
 *
 * bot_state keys owned here: collected (details the customer gave, never photos),
 * asks (per intent), awaiting / awaiting_intent (what the last ask waits for),
 * order_id, clarify_count, last_intents, last_answered, repeat_count. They are cleared
 * when a lookup resolves and on every handover; other keys (last_turn_message_id,
 * delayed_response_sent) are kept.
 */
class TurnRunner
{
    /** The one button a direct answer carries (agent rebuild §5). */
    public const MENU_BUTTON = ['title' => 'القائمة 📋', 'payload' => 'menu:main_menu'];

    /** A case of the same flow younger than this is offered to a person instead of restarting the flow. */
    public const CASE_EXISTS_HOURS = 24;

    /** A flow prompt that follows a delayed reply part is queued this long after that part. */
    public const FOLLOW_UP_GAP_MS = 1000;

    public const CLARIFY_TEXT = 'ممكن توضحيلي حضرتك محتاجة إيه بالظبط عشان أقدر أساعدك؟ 🌸';

    public const NOT_FOUND_TEXT = 'مش لاقية أوردر بالبيانات دي 🌸';

    /** Collect intents whose handover summary carries the 2-hour cancel/edit window. */
    public const WINDOW_INTENTS = ['cancel_order', 'edit_order'];

    /** Lookup intents that go to a person even when the order looks on time. */
    public const ALWAYS_AGENT_LOOKUPS = ['delayed_order', 'no_update'];

    /** How many times a collect or lookup intent asks for details before a person takes over. */
    public const MAX_ASKS = 2;

    /** Words besides the order detail itself that still count as "just the detail" (final fix wave M3). */
    private const MAX_FILLER_WORDS = 2;

    private const RANK = ['low' => 1, 'medium' => 2, 'high' => 3];

    /** The burst texts of the turn currently in run(), for bodies()'s scarves-link swap (change 3/4). */
    private array $currentBurstTexts = [];

    /** When the customer's last message of this burst was stored: the typing delay counts from here. */
    private ?CarbonInterface $burstEndedAt = null;

    /** The collect/lookup intent the previous turn was waiting on (bot_state.awaiting_intent). */
    private ?string $pendingIntentKey = null;

    /** Next-step hints for the compose model (reply flow v2), by missing detail token. */
    private const DETAIL_HINTS = [
        'order_ref' => 'the order number, mobile number or email used for the order',
        'phone' => 'the order number, mobile number or email used for the order',
        'email' => 'the order number, mobile number or email used for the order',
        'photos' => 'a clear photo of the product',
        'product_photo' => 'a clear photo of the product',
        'tag_photo' => 'a photo of the product tag',
        'invoice' => 'a photo of the invoice',
    ];

    /** @var list<string> hints gathered during run() for the compose model */
    private array $nextSteps = [];

    /** The longest send delay deliver() queued during the current run() (0 when every part went out now). */
    private int $queuedDelayMs = 0;

    public function __construct(
        private readonly TurnUnderstanding $understanding,
        private readonly IntentRouter $router,
        private readonly ReplyComposer $composer,
        private readonly HumanPacing $pacing,
        private readonly OutboundService $outbound,
        private readonly KnowledgeBase $knowledge,
        private readonly ScriptPlaceholders $placeholders,
    ) {}

    /**
     * @param  bool  $flowContext  the customer asked a question in the middle of a guided flow: answer it
     *                             only (no flow start, no clarifying question, no handover for unclear)
     */
    public function run(Conversation $c, Collection $burst, bool $flowContext = false): BotRun
    {
        $s = BotSetting::current();
        $catalog = app(IntentCatalog::class);
        $collector = app(DetailsCollector::class);
        $texts = $burst->pluck('body')->map(fn ($b) => (string) $b)->filter(fn ($b) => trim($b) !== '')->values()->all();
        $this->currentBurstTexts = $texts;
        $this->burstEndedAt = $burst->last()?->created_at;
        $turnStarted = hrtime(true);
        $previousState = $c->bot_state ?? [];
        $this->pendingIntentKey = $previousState['awaiting_intent'] ?? null;
        $this->nextSteps = [];
        $this->queuedDelayMs = 0;
        $hasImage = $this->burstHasImage($burst);

        // Final fix wave M2: photos without words have nothing to understand, so no understanding call.
        $photoOnly = $texts === [] && $hasImage;
        $aiFailed = false;

        // Speed (2026-09-16): a burst of only short greetings/thanks ("مساء الفل", "شكرا") is answered
        // straight from its script, without waiting seconds for the understanding call.
        $socialKeys = $hasImage || $texts === [] ? [] : array_map(fn (string $t) => app(BurstPolicy::class)->socialIntent($t, 2), $texts);
        $socialOnly = $socialKeys !== [] && ! in_array(null, $socialKeys, true);

        if ($photoOnly) {
            $u = new Understanding([], array_fill_keys(Understanding::ENTITY_KEYS, null), 'neutral', false, false, 'ar');
        } elseif ($socialOnly) {
            $intents = array_map(fn (string $k) => ['key' => $k, 'confidence' => 1.0], array_values(array_unique($socialKeys)));
            $u = new Understanding($intents, array_fill_keys(Understanding::ENTITY_KEYS, null), 'positive', false, false, 'ar');
        } else {
            try {
                $u = $this->understanding->understand($this->history($c, $burst), $texts, $catalog->forPrompt());
            } catch (Throwable $e) {
                report($e);
                $aiFailed = true;
                $u = app(FakeTurnUnderstanding::class)->understand([], $texts, $catalog->forPrompt());
            }
        }

        $plan = $this->router->plan($u, $catalog, $previousState, (float) $s->min_confidence);

        // Photos count for this burst only; they are never remembered.
        $entities = $u->entities;

        if ($hasImage) {
            $entities['photos'] = 'yes';
        }

        // Reply flow v2: an image sent while a collect intent waits for details (or starts one)
        // is remembered for that collect only; forget() and every handover clear it.
        $pendingCollect = $catalog->find((string) ($previousState['awaiting_intent'] ?? ''));
        $keepPhotos = $hasImage && ($plan->collectIntent !== null || $pendingCollect?->route === 'collect_then_handover');
        $state = ['collected' => $collector->remember($previousState, $entities, $keepPhotos)['collected']];
        $asks = (array) ($previousState['asks'] ?? []);
        // Snapshot before collect()/applyLookup() clear it on their own resolution (forget()),
        // so the handover summary can still list what the customer had already given.
        $collectedForSummary = $state['collected'];

        $scripts = [];
        $facts = [];
        $templates = [];
        $handoverNotes = [];
        $handover = $plan->handover;
        $answerIntents = $plan->answerIntents;
        $lookupIntents = $plan->lookupIntents;
        $collectIntent = $plan->collectIntent;
        $clarify = $plan->clarify;

        // Agent rebuild §5: an intent with an active flow starts that flow; the other answer intents are
        // still answered first, while its own collect / lookup / handover handling is skipped.
        $flowIntent = $flowContext ? null : $this->flowIntent($plan);

        if ($flowIntent !== null) {
            $answerIntents = array_values(array_filter($answerIntents, fn (BotIntent $i) => $i->key !== $flowIntent->key));
            // Only intents that belong to a flow leave the v2 path; flowIntent() never starts a flow while a
            // collect/lookup without a flow is planned, so nothing unrelated is dropped here.
            $lookupIntents = array_values(array_filter($lookupIntents, fn (BotIntent $i) => ! $this->hasActiveFlow($i)));
            $collectIntent = $this->hasActiveFlow($collectIntent) ? null : $collectIntent;
            $clarify = false;
            $handover = null;
        }

        if ($flowContext) {
            $clarify = false;
            $handover = ($handover['category'] ?? null) === 'unclear' ? null : $handover;
        }

        $understood = $answerIntents !== [] || $lookupIntents !== [] || $collectIntent !== null || $flowIntent !== null;

        // A reply to "send me the order number / the details" runs the intent that asked again: when it
        // has no intent of its own, or when it is only an order detail, whatever lookup the understanding
        // guessed for it (final fix wave M3).
        $resume = $flowIntent === null && (! $understood || $this->isOnlyOrderDetail($texts))
            ? $this->resumeIntent($catalog, $previousState, $entities, $texts)
            : null;

        if ($resume !== null) {
            $lookupIntents = $resume->route === 'lookup' ? [$resume] : [];
            $collectIntent = $resume->route === 'lookup' ? null : $resume;
            $clarify = false;
            $handover = ($handover['category'] ?? null) === 'unclear' ? null : $handover;
            $understood = true;
        } elseif ($photoOnly && ($availability = $catalog->find('availability')) !== null) {
            // The owner's "send the link / tell us which model" answer instead of a clarifying question.
            $answerIntents = [$availability];
            $clarify = false;
            $handover = null;
            $understood = true;
        } elseif ($understood && filled($previousState['awaiting_intent'] ?? null)) {
            $planned = array_map(fn (BotIntent $i) => (string) $i->key, array_filter([...$lookupIntents, $collectIntent]));

            if (! in_array($previousState['awaiting_intent'], $planned, true)) {
                // The customer moved on to something else: the old ask no longer waits for an answer.
                unset($asks[$previousState['awaiting_intent']]);
                $state['awaiting'] = null;
                $state['awaiting_intent'] = null;
            }
        }

        // Spec §4: understanding failed and the keyword fallback cannot tell either → a person, no clarifying question.
        $aiError = $aiFailed && ($u->unclear || ! $understood);

        if ($aiError) {
            $handover = ['priority' => 'medium', 'queue' => 'agents', 'category' => 'ai_error', 'reason' => 'ai_error'];
        }

        // Final fix wave I5: a phone or an address that no ask or lookup is waiting for is an order being placed.
        $newOrder = ! $aiError && $flowIntent === null && $collectIntent === null && $lookupIntents === []
            && blank($previousState['awaiting_intent'] ?? null)
            && $this->hasContactDetails($entities, $texts);

        if ($newOrder) {
            $newOrderHandover = $this->handoverFor('medium', 'agents', 'new_order', 'new_order');
            $handover = ($handover['category'] ?? null) === 'unclear' ? $newOrderHandover : $this->stronger($handover, $newOrderHandover);
            $clarify = false;
        }

        // Reply flow v2: product questions (price, material…) answer with their scripts, which carry
        // the website link; catalog facts and the owner's blank templates are no longer used here.
        foreach ($answerIntents as $i) {
            $scripts = array_merge($scripts, $this->scripts($i));
        }

        $facts = array_values(array_unique($facts));

        if ($collectIntent) {
            $handover = $this->stronger($handover, $this->collect($c, $collectIntent, $entities, $state, $asks, $scripts, $handoverNotes, (bool) $s->order_lookup_enabled));
        }

        $lookup = null;

        foreach ($lookupIntents as $i) {
            if (! $s->order_lookup_enabled) {
                // No lookup: ask for the order details like a collect intent, then a person answers.
                $handover = $this->stronger($handover, $this->collect($c, $i, $entities, $state, $asks, $scripts, $handoverNotes, false));

                continue;
            }

            // Several tracking intents in one burst share one lookup.
            $lookup ??= app(OrderLookup::class)->find($c, $state['collected']);
            $handover = $this->stronger($handover, $this->applyLookup($i, $lookup, $state, $asks, $scripts, $facts, $handoverNotes));
        }

        $state['asks'] = $asks;

        if ($clarify && ! $aiError) {
            $scripts[] = self::CLARIFY_TEXT;
            $this->nextSteps[] = 'She was not clear. Ask her politely what exactly she needs; do not guess.';
            $state['clarify_count'] = (int) ($previousState['clarify_count'] ?? (empty($previousState['clarified']) ? 0 : 1)) + 1;
            $state['clarified'] = null;
        } elseif ($understood) {
            // Understood this time: a later unclear message gets its own clarifying questions.
            $state['clarify_count'] = 0;
            $state['clarified'] = null;
        }

        $facts = array_values(array_unique($facts));

        if ($newOrder && ($scripts !== [] || $facts !== [])) {
            // Answered questions go out together with the message that a person takes the order.
            $scripts = array_merge($scripts, $this->bodies(['order_via_agent']) ?: $this->bodies(['handover_ack']));
            $this->nextSteps[] = 'Tell her to wait a moment because a colleague will register the order with her.';
        }

        $reply = null;
        $delivery = ['sent' => false, 'stopped' => null];

        // A direct answer outside any flow carries the menu button, which replaces the offer of a person.
        $menuButton = $flowIntent === null && ! $flowContext && FlowState::flow($c) === null && BotFlow::active('main_menu') !== null;

        if ($scripts === [] && $facts === []) {
            if ($flowIntent !== null) {
                // The flow's first step speaks next; only the first bot reply opens with the greeting.
                $reply = $this->isFirstBotReply($c) ? ($this->bodies(['greeting'])[0] ?? null) : null;
            } elseif (! $flowContext || $handover !== null || $aiError) {
                // Nothing approved to say (e.g. the script is still a ❓ placeholder, or an ai_error):
                // a greeting alone is not an answer, so a person takes it. Inside a flow an unanswerable
                // question just gets the waiting step again.
                $handover ??= ['priority' => 'medium', 'queue' => 'agents', 'category' => 'no_script', 'reason' => 'no_script'];
            }
        } else {
            $firstReply = $this->isFirstBotReply($c);

            if ($this->previousBotReplyHasStoreLink($c, $burst)) {
                $this->nextSteps[] = 'The website link was already sent in your previous reply; do not repeat the link unless she asks for it again.';
            }

            $reply = $this->composer->compose(
                $c,
                array_values(array_unique($scripts)),
                $facts,
                $u,
                $firstReply,
                array_values(array_unique($templates)),
                $texts,
                $socialOnly ? [] : $this->history($c, $burst),
                array_values(array_unique($this->nextSteps)),
                allowAi: ! $socialOnly,
            );

            // Reply flow v2: the first reply offers a person once, unless this turn already hands over.
            if ($reply !== null && $firstReply && $handover === null && ! $menuButton && $flowIntent === null && ($offer = $this->offerLine()) !== null && ! str_contains($reply, $offer)) {
                $reply = rtrim($reply)."\n\n".$offer;
            }
        }

        if ($reply !== null && trim($reply) !== '') {
            // A turn that hands over keeps its own answer: its later part must not be dropped by that handover.
            $buttons = $menuButton && $handover === null ? [self::MENU_BUTTON] : [];
            $delivery = $this->deliver($c, $reply, (int) $s->typing_ms_per_char, $turnStarted, skipLaterPartsIfHumanTakesOver: $handover === null, buttons: $buttons);
        } else {
            $reply = null;
        }

        $flowStarts = $flowIntent !== null && $handover === null && $delivery['stopped'] === null;
        $answered = $delivery['sent'] || $flowStarts;

        if ($delivery['stopped'] === 'window_closed') {
            $handover ??= ['priority' => 'medium', 'queue' => 'agents', 'category' => 'window_closed', 'reason' => 'window_closed'];
        }

        // Final fix wave I2: a handover never leaves the customer in silence.
        if ($handover !== null && ! $delivery['sent'] && $delivery['stopped'] === null && ($ack = $this->handoverAck($handover, $previousState)) !== null) {
            $delivery = $this->deliver($c, $ack['text'], (int) $s->typing_ms_per_char, $turnStarted, skipLaterPartsIfHumanTakesOver: false);

            if ($delivery['sent']) {
                $reply = $ack['text'];
            }

            if ($delivery['sent'] && $ack['delayed_response']) {
                $state['delayed_response_sent'] = true;
            }
        }

        // Final fix wave I10: a person already answering stops the bot, including its own handover.
        $humanTookOver = $delivery['stopped'] === 'human_took_over';

        if ($humanTookOver) {
            $handover = null;
        }

        if ($handover !== null) {
            // A person takes it from here: nothing collected so far may leak into a later bot conversation.
            $state['collected'] = [];
            $state['asks'] = [];
            $state['awaiting'] = null;
            $state['awaiting_intent'] = null;
        }

        // Final fix wave I11: asking the same thing again only counts when the last turn left it unanswered.
        $keys = $u->keys();
        $state['last_intents'] = $keys;
        $state['last_answered'] = $answered;
        $state['repeat_count'] = (($previousState['last_intents'] ?? null) === $keys && empty($previousState['last_answered']))
            ? (int) ($previousState['repeat_count'] ?? 0) + 1
            : 0;

        // Merge onto the freshest state so RunBotTurn's last_turn_message_id is kept.
        $c->forceFill(['bot_state' => array_merge($c->bot_state ?? [], $state)])->save();

        $caseExists = false;

        if ($flowStarts && $c->refresh()->handler === Handler::Bot) {
            $caseExists = $this->startFlow($c, (string) $flowIntent->flow_key, $this->followUpDelayMs());
        }

        if ($handover !== null) {
            // The customer text stays exactly what she typed (spec §2.1 HandoverRouter, Task 4
            // ruling 2); collected details, order line(s) and the cancel/edit window move to the
            // note's own extra lines instead of riding inside it.
            $summaryExtra = array_values(array_unique(array_filter(array_merge(
                [$this->collectedSummaryLine($collectedForSummary)],
                $handoverNotes,
            ))));

            app(HandoverRouter::class)->route($c, $handover, implode("\n", $texts), $summaryExtra);
        }

        $compose = $this->composer->lastUsage();
        $inputTokens = $u->inputTokens + $compose['input_tokens'];
        $outputTokens = $u->outputTokens + $compose['output_tokens'];
        $sent = $delivery['sent'];

        return BotRun::create([
            'conversation_id' => $c->id,
            'trigger_message' => implode("\n", $texts),
            'engine' => $flowIntent !== null ? 'flow_engine' : 'flow',
            'model' => $compose['model'] ?: ($u->model !== '' ? $u->model : null),
            'intent' => $keys !== [] ? mb_substr(implode(',', $keys), 0, 190) : null,
            'confidence' => $keys !== [] ? $u->topConfidence() : null,
            'decision' => match (true) {
                $humanTookOver => 'human_took_over',
                $flowStarts => $caseExists ? 'case_exists' : 'flow_started',
                $handover !== null => $sent ? 'reply_and_handover' : 'handover',
                default => 'reply',
            },
            'reply_text' => $sent ? $reply : null,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_usd' => round($this->cost($u->model, $u->inputTokens, $u->outputTokens) + $this->cost($compose['model'], $compose['input_tokens'], $compose['output_tokens']), 4),
            'latency_ms' => (int) ((hrtime(true) - $turnStarted) / 1_000_000),
        ]);
    }

    /**
     * The delay a message sent right after the last run() must use so it arrives after that run's
     * delayed reply parts (a flow prompt after a split answer; its quick replies must stay last).
     */
    public function followUpDelayMs(): int
    {
        return $this->queuedDelayMs > 0 ? $this->queuedDelayMs + self::FOLLOW_UP_GAP_MS : 0;
    }

    /**
     * Paced send (final fix wave I3, I10): one typing wait before the first part (typing already
     * started when the burst was scheduled, and HumanPacing caps the wait); every later part is
     * queued with its own typing delay instead of sleeping in the job. The handler is re-read
     * right before each part is created, so a person who took over stops the rest.
     *
     * @param  bool  $skipLaterPartsIfHumanTakesOver  also re-check the handler when a later, delayed part goes out
     * @return array{sent: bool, stopped: 'human_took_over'|'window_closed'|null}
     */
    private function deliver(Conversation $c, string $text, int $msPerChar, int $turnStarted, bool $skipLaterPartsIfHumanTakesOver, array $buttons = []): array
    {
        $sent = false;
        $parts = $this->pacing->split($text);
        $lastIndex = array_key_last($parts);

        foreach ($parts as $i => $part) {
            // Quick replies only show under the newest message on Messenger, so the buttons ride the last part.
            $partButtons = $i === $lastIndex ? $buttons : [];

            if ($i === 0) {
                // Speed (2026-09-16): the burst wait already looked like typing to the customer, so the
                // time since her last message counts toward the typing delay, not just this job's time.
                $sinceCustomerMs = $this->burstEndedAt !== null ? (int) abs(now()->diffInMilliseconds($this->burstEndedAt)) : 0;
                $this->pacing->beforeSend($c, $part, $msPerChar, max((int) ((hrtime(true) - $turnStarted) / 1_000_000), $sinceCustomerMs));
            }

            if ($c->refresh()->handler !== Handler::Bot) {
                return ['sent' => $sent, 'stopped' => 'human_took_over'];
            }

            try {
                $delayMs = $i === 0 ? 0 : $this->pacing->delayMs($part, $msPerChar);
                $this->outbound->sendBot($c, $part, $delayMs, $i === 0 ? false : $skipLaterPartsIfHumanTakesOver, $partButtons);
                $this->queuedDelayMs = max($this->queuedDelayMs, $delayMs);
                $sent = true;
            } catch (WindowClosedException) {
                return ['sent' => $sent, 'stopped' => 'window_closed'];
            }
        }

        return ['sent' => $sent, 'stopped' => null];
    }

    /**
     * What the customer reads when a person takes over and nothing else was sent (final fix wave I2):
     * script.handover_ack, or once per conversation script.delayed_response for a repeated question.
     * Nothing when the reply window is closed (sending is impossible).
     *
     * @return array{text:string, delayed_response:bool}|null
     */
    private function handoverAck(array $handover, array $previousState): ?array
    {
        $category = $handover['category'] ?? null;

        if ($category === 'window_closed') {
            return null;
        }

        if ($category === 'repeated' && empty($previousState['delayed_response_sent']) && ($text = $this->bodies(['delayed_response'])[0] ?? null) !== null) {
            return ['text' => $text, 'delayed_response' => true];
        }

        // Reply flow v2: a handover that has its own message (a handover intent's script, or the
        // order-through-us script for contact details) sends it instead of the generic ack.
        $intent = ($handover['reason'] ?? null) === 'intent' ? app(IntentCatalog::class)->find((string) $category) : null;
        $ownKeys = match (true) {
            $category === 'new_order' => ['order_via_agent'],
            $intent !== null && $intent->route === 'handover' => (array) ($intent->script_keys ?? []),
            default => [],
        };

        $text = $this->bodies($ownKeys)[0] ?? $this->bodies(['handover_ack'])[0] ?? null;

        return $text !== null ? ['text' => $text, 'delayed_response' => false] : null;
    }

    /**
     * The planned intent whose flow starts this turn: collect, then lookup, then a handover intent, then
     * answer. None when a handover that belongs to no flow intent (angry, a person asked for) wins, or
     * when the burst also carries a collect/lookup intent without a flow: that one runs normally this
     * turn (with the rest of the plan) and the flow waits until she asks again.
     */
    private function flowIntent(TurnPlan $plan): ?BotIntent
    {
        $handoverIntent = ($plan->handover['reason'] ?? null) === 'intent'
            ? app(IntentCatalog::class)->find((string) $plan->handover['category'])
            : null;

        if ($plan->handover !== null && ! $this->hasActiveFlow($handoverIntent)) {
            return null;
        }

        foreach (array_filter([$plan->collectIntent, ...$plan->lookupIntents]) as $intent) {
            if (! $this->hasActiveFlow($intent)) {
                return null;
            }
        }

        foreach ([$plan->collectIntent, ...$plan->lookupIntents, $handoverIntent, ...$plan->answerIntents] as $intent) {
            if ($this->hasActiveFlow($intent)) {
                return $intent;
            }
        }

        return null;
    }

    private function hasActiveFlow(?BotIntent $intent): bool
    {
        return $intent !== null && filled($intent->flow_key) && BotFlow::active((string) $intent->flow_key) !== null;
    }

    /**
     * Starts the flow, or, when this conversation has an open case of that flow younger than
     * CASE_EXISTS_HOURS, says the request is already recorded and offers a person (yes/no) instead.
     *
     * @return bool true when the case-exists offer was sent instead of starting the flow
     */
    private function startFlow(Conversation $c, string $flowKey, int $delayMs = 0): bool
    {
        $types = $this->flowCaseTypes($flowKey);
        $case = $types === [] ? null : SupportCase::query()
            ->where('conversation_id', $c->id)
            ->whereIn('type', $types)
            ->where('status', '!=', 'closed')
            ->where('created_at', '>=', now()->subHours(self::CASE_EXISTS_HOURS))
            ->latest('id')
            ->first();

        if ($case === null) {
            app(FlowEngine::class)->start($c, $flowKey, delayMs: $delayMs);

            return false;
        }

        $text = app(FlowPrompter::class)->script('flow_case_exists', ['case_id' => $case->id])
            ?? str_replace('{case_id}', (string) $case->id, (string) (FlowScripts::all()['flow_case_exists']['body'] ?? ''));

        try {
            $this->outbound->sendBot($c, $text, $delayMs, true, [['title' => 'أيوه', 'payload' => 'yes'], ['title' => 'لأ', 'payload' => 'no']]);
            FlowState::setConfirm($c, 'handover_offer');
        } catch (WindowClosedException) {
            // Nothing can reach her now; the recorded case is already with the team.
        }

        return true;
    }

    /** @return list<string> the case types a flow records: its record_case steps, and a status step's follow-up */
    private function flowCaseTypes(string $flowKey): array
    {
        $types = [];

        foreach ((array) (BotFlow::active($flowKey)?->definition['steps'] ?? []) as $step) {
            if (($step['type'] ?? null) === 'record_case') {
                $types[] = (string) ($step['case_type'] ?? '');
            } elseif (($step['type'] ?? null) === 'status') {
                $types[] = 'delivery_followup';
            }
        }

        return array_values(array_unique(array_filter($types)));
    }

    /** A phone number (understood or matched) or a written address (spec §4.1 HandoverSignals). */
    private function hasContactDetails(array $entities, array $texts): bool
    {
        return filled($entities['phone'] ?? null)
            || app(HandoverSignals::class)->detect(implode("\n", $texts)) === 'contact_details';
    }

    /** The burst is an order number, phone or email, with at most a couple of other words (final fix wave M3). */
    private function isOnlyOrderDetail(array $texts): bool
    {
        $text = app(ArabicNormalizer::class)->digitsToLatin(implode(' ', $texts));
        $rest = preg_replace(['/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '/\+?\d[\d \-]{6,}\d/', '/#?\d{3,}/'], ' ', $text) ?? $text;

        if ($rest === $text) {
            return false;
        }

        $rest = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $rest) ?? $rest;

        return count(preg_split('/\s+/u', trim($rest), -1, PREG_SPLIT_NO_EMPTY)) <= self::MAX_FILLER_WORDS;
    }

    /**
     * Collect-then-handover (spec §2.1 DetailsCollector): ask with the intent's script while details
     * are missing (at most MAX_ASKS times, no handover yet); then hand over with the intent's
     * priority/queue. Complete details get one acknowledgement (script.handover_ack) first;
     * cancel/edit adds the remaining cancel window to the handover notes.
     *
     * @return array{priority:string, queue:string, category:string, reason:string}|null
     */
    private function collect(Conversation $c, BotIntent $i, array $entities, array &$state, array &$asks, array &$scripts, array &$notes, bool $lookupEnabled): ?array
    {
        $key = (string) $i->key;
        $missing = app(DetailsCollector::class)->missing($i, $entities, [
            'collected' => $state['collected'],
            'asks' => $asks,
            'awaiting_intent' => array_key_exists('awaiting_intent', $state) ? $state['awaiting_intent'] : $this->pendingIntentKey,
        ]);

        if ($missing !== [] && (int) ($asks[$key] ?? 0) < self::MAX_ASKS) {
            $scripts = array_merge($scripts, $i->route === 'lookup' ? $this->askScripts($i) : $this->scripts($i));
            $asks[$key] = (int) ($asks[$key] ?? 0) + 1;
            $state['awaiting'] = $this->needsOrderDetail($missing) ? 'order_ref' : 'details';
            $state['awaiting_intent'] = $key;
            $this->nextSteps[] = $this->missingHint($missing);

            return null;
        }

        if ($missing === []) {
            $scripts = array_merge($scripts, $this->bodies(['handover_ack']));
            $this->nextSteps[] = 'Tell her you received the details and a colleague will review her request now. Do not promise a time and do not ask for anything else.';
        }

        if ($lookupEnabled && in_array($key, self::WINDOW_INTENTS, true)) {
            $found = app(OrderLookup::class)->find($c, $state['collected']);

            if ($found['status'] === 'found') {
                $snap = $found['snapshots'][0];
                // Task 4: the order line and the cancel window go to the handover summary's
                // extra lines, never into the customer-facing text.
                $notes[] = app(OrderStatusText::class)->line($snap);
                $left = app(OrderLookup::class)->cancelWindowLeftMinutes($snap, CarbonImmutable::now());
                $notes[] = $left > 0 ? "باقي على مهلة الإلغاء/التعديل: {$left} دقيقة" : 'انتهت مهلة الإلغاء/التعديل';
                $state['order_id'] = $snap->orderId;
            }
        }

        $this->forget($state, $asks, $key);

        return $this->handoverFor((string) $i->priority, $i->queue ?: 'agents', $key, 'intent');
    }

    /**
     * @param  array{status:string, snapshots:list<OrderSnapshot>}  $r
     * @param  array<int, string>  $notes  handover summary extra lines (order line, cancel window)
     * @return array{priority:string, queue:string, category:string, reason:string}|null
     */
    private function applyLookup(BotIntent $i, array $r, array &$state, array &$asks, array &$scripts, array &$facts, array &$notes): ?array
    {
        $key = (string) $i->key;
        $text = app(OrderStatusText::class);

        if (in_array($r['status'], ['missing_details', 'multiple'], true)) {
            if ((int) ($asks[$key] ?? 0) >= self::MAX_ASKS) {
                $this->forget($state, $asks, $key);

                return $this->handoverFor('medium', 'agents', 'order_details_missing', 'order_details_missing');
            }

            $asks[$key] = (int) ($asks[$key] ?? 0) + 1;
            $state['awaiting'] = 'order_ref';
            $state['awaiting_intent'] = $key;

            if ($r['status'] === 'missing_details') {
                $scripts = array_merge($scripts, $this->askScripts($i));
                $this->nextSteps[] = 'Ask only for the order number, mobile number or email used for the order.';
            } else {
                $list = array_map(fn (OrderSnapshot $s) => $s->number.' ('.$s->placedAt->setTimezone(OrderStatusText::TIMEZONE)->format('j/n').')', $r['snapshots']);
                $facts[] = 'لقيت أكتر من أوردر: '.implode('، ', $list).' تحبي أتابع أنهي واحد؟';
            }

            return null;
        }

        $this->forget($state, $asks, $key);

        if ($r['status'] === 'not_found') {
            $facts[] = self::NOT_FOUND_TEXT;

            return $this->handoverFor('medium', 'agents', 'order_not_found', 'order_not_found');
        }

        $snap = $r['snapshots'][0];
        $facts[] = $text->line($snap);
        // Task 4: the same status line goes to the handover summary too (an agent needs the
        // order snapshot even though the customer already read it in the reply).
        $notes[] = $text->line($snap);
        $state['order_id'] = $snap->orderId;

        $handover = null;

        if ($snap->failedAttempt) {
            $handover = $this->handoverFor('medium', 'agents', 'failed_delivery_attempt', 'failed_delivery_attempt');
        }

        if ($snap->statusKey === 'hold') {
            $handover = $this->stronger($handover, $this->handoverFor('medium', 'agents', 'order_hold', 'order_hold'));
        } elseif ($snap->statusKey === 'returned') {
            $handover = $this->stronger($handover, $this->handoverFor('medium', 'agents', 'order_returned', 'order_returned'));
        }

        if ($text->isDelayed($snap, CarbonImmutable::now()) || in_array($key, self::ALWAYS_AGENT_LOOKUPS, true)) {
            $handover = $this->stronger($handover, $this->handoverFor('high', 'agents', 'delayed_order', 'delayed_order'));
        }

        return $handover;
    }

    /** A resolved lookup/collect: its details, ask count and pending ask are done. */
    private function forget(array &$state, array &$asks, string $key): void
    {
        unset($asks[$key]);
        $state['collected'] = [];
        $state['awaiting'] = null;
        $state['awaiting_intent'] = null;
    }

    /** The intent that asked for details last turn, when this burst looks like the answer. */
    private function resumeIntent(IntentCatalog $catalog, array $previousState, array $entities, array $texts): ?BotIntent
    {
        $awaiting = $previousState['awaiting'] ?? null;
        $intent = $catalog->find((string) ($previousState['awaiting_intent'] ?? ''));

        if ($intent === null || ! in_array($intent->route, ['lookup', 'collect_then_handover'], true)) {
            return null;
        }

        $gaveOrderDetail = collect(['order_ref', 'phone', 'email'])->contains(fn ($k) => filled($entities[$k] ?? null));

        return match ($awaiting) {
            'order_ref' => $gaveOrderDetail || isset($entities['photos']) ? $intent : null,
            'details' => $texts !== [] || isset($entities['photos']) ? $intent : null,
            default => null,
        };
    }

    /** @param  list<string>  $missing  unsatisfied required_details tokens */
    private function missingHint(array $missing): string
    {
        $parts = [];

        foreach ($missing as $token) {
            $first = explode('|', $token)[0];
            $parts[] = self::DETAIL_HINTS[$first] ?? str_replace('_', ' ', $first);
        }

        return 'Ask only for what is still missing: '.implode('; ', array_values(array_unique($parts))).'. Do not ask again for anything she already sent.';
    }

    /** The latest bot message before this burst already carried the store or scarves link. */
    private function previousBotReplyHasStoreLink(Conversation $c, Collection $burst): bool
    {
        $body = (string) $c->messages()
            ->where('sender_type', SenderType::Bot->value)
            ->when($burst->first()?->id, fn ($q, $id) => $q->where('id', '<', $id))
            ->latest('id')
            ->value('body');

        return str_contains($body, LeVoileScripts::STORE_URL) || str_contains($body, LeVoileScripts::SCARVES_URL);
    }

    /** script.offer_human when active and non-empty. */
    private function offerLine(): ?string
    {
        return $this->bodies(['offer_human'])[0] ?? null;
    }

    /** @param  list<string>  $missing */
    private function needsOrderDetail(array $missing): bool
    {
        return collect($missing)->contains(fn (string $t) => (bool) array_intersect(explode('|', $t), ['order_ref', 'phone', 'email']));
    }

    /**
     * One handover-summary line listing whatever the customer already gave this
     * conversation (spec §2.1 HandoverRouter's "collected details"), from the Understanding
     * entity keys (spec §2.1 TurnUnderstanding).
     *
     * @param  array<string, mixed>  $collected
     */
    private function collectedSummaryLine(array $collected): ?string
    {
        $labels = [
            'order_ref' => 'رقم الأوردر', 'phone' => 'التليفون', 'email' => 'الإيميل',
            'governorate' => 'المحافظة', 'product' => 'المنتج', 'size' => 'المقاس', 'color' => 'اللون',
        ];

        $parts = [];

        foreach ($labels as $key => $label) {
            $value = $collected[$key] ?? null;

            if ($value !== null && $value !== '') {
                $parts[] = "{$label}: {$value}";
            }
        }

        return $parts === [] ? null : 'البيانات المجمعة: '.implode('، ', $parts);
    }

    /**
     * The "send me the order number" script of a tracking intent: its first script only, so a
     * follow-up like "we contacted the courier" is never sent before an order was found.
     *
     * @return list<string>
     */
    private function askScripts(BotIntent $i): array
    {
        return $this->bodies(array_slice($i->script_keys ?? [], 0, 1));
    }

    /** @return array{priority:string, queue:string, category:string, reason:string} */
    private function handoverFor(string $priority, string $queue, string $category, string $reason): array
    {
        return ['priority' => $priority, 'queue' => $queue, 'category' => $category, 'reason' => $reason];
    }

    /** Highest priority wins; ties keep the current one. */
    private function stronger(?array $current, ?array $candidate): ?array
    {
        if ($candidate === null) {
            return $current;
        }

        if ($current === null) {
            return $candidate;
        }

        return (self::RANK[$candidate['priority']] ?? 1) > (self::RANK[$current['priority']] ?? 1) ? $candidate : $current;
    }

    private function burstHasImage(Collection $burst): bool
    {
        return $burst->contains(fn (Message $m) => collect($m->attachments ?? [])->contains(fn ($a) => is_array($a) && ($a['type'] ?? null) === 'image')
            || $m->mediaAttachments()->where('type', AttachmentType::Image->value)->exists());
    }

    /** @return list<string> active `script.*` bodies for the intent, in its script_keys order */
    private function scripts(BotIntent $i): array
    {
        return $this->bodies($i->script_keys ?? []);
    }

    /**
     * @param  list<string>  $keys  script keys without the "script." prefix
     * @return list<string> active, non-empty bodies in the given order
     *
     * The single place TurnRunner collects script bodies (spec for overnight refinement
     * change 1/3/4): {time_greeting} is rendered and the scarves link swap is applied here,
     * before the text reaches ReplyComposer, so PriceGuard/PromiseGuard compare against what
     * is actually sent.
     */
    private function bodies(array $keys): array
    {
        $bodies = [];
        $mentionsScarves = null;

        foreach ($keys as $key) {
            $body = trim((string) $this->knowledge->get('script.'.$key)?->body);

            if ($body === '') {
                continue;
            }

            $body = $this->placeholders->render($body);

            if (in_array($key, LeVoileScripts::SCARF_SWAP_SCRIPT_KEYS, true) && str_contains($body, LeVoileScripts::STORE_URL)) {
                $mentionsScarves ??= LeVoileScripts::mentionsScarves(implode(' ', $this->currentBurstTexts));

                if ($mentionsScarves) {
                    $body = str_replace(LeVoileScripts::STORE_URL, LeVoileScripts::SCARVES_URL, $body);
                }
            }

            $bodies[] = $body;
        }

        return $bodies;
    }

    private function isFirstBotReply(Conversation $c): bool
    {
        return ! $c->messages()->where('sender_type', SenderType::Bot->value)->exists();
    }

    /** @return list<array{role:'customer'|'agent', text:string}> the last 12 messages before the burst */
    private function history(Conversation $c, Collection $burst): array
    {
        $firstId = $burst->first()?->id;

        return $c->messages()
            ->when($firstId, fn ($q) => $q->where('id', '<', $firstId))
            ->orderByDesc('id')
            ->limit(12)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (Message $m) => [
                'role' => $m->sender_type === SenderType::Customer ? 'customer' : 'agent',
                'text' => (string) $m->body,
            ])
            ->all();
    }

    private function cost(string $model, int $in, int $out): float
    {
        $price = $model !== '' ? (config('crm.anthropic.prices', [])[$model] ?? null) : null;

        return $price === null ? 0.0 : ($in / 1_000_000) * $price['input'] + ($out / 1_000_000) * $price['output'];
    }
}
