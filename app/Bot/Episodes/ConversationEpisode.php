<?php

namespace App\Bot\Episodes;

use App\Bot\ArabicNormalizer;
use App\Bot\BotEngine;
use App\Bot\Flows\ConversationRouter;
use App\Bot\Flows\FlowEngine;
use App\Bot\Flows\FlowLabels;
use App\Bot\Flows\FlowPrompter;
use App\Bot\Flows\FlowScripts;
use App\Bot\Flows\FlowState;
use App\Bot\Flows\HumanHandover;
use App\Bot\Router\TaskQueue;
use App\Enums\SenderType;
use App\Inbox\OutboundService;
use App\Inbox\WindowClosedException;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\QueueSetting;
use App\Models\SupportCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * «A new message after a close» (staff complaints, round b1, 2026-10-19) — ONE rule for both
 * routers:
 *
 *   - Every close leaves a marker in `bot_state.episode`: the team's close («خلصت», «رجوع للبوت»,
 *     a resolve, the queue's auto-close — all through Conversation::resetBotState()), the bot's own
 *     end of a request (a flow that reached its end), or HER close («لا تمام شكرا», «شكرا»).
 *   - Her next message, inside the reopen window (the queue's `reopen_after_done_minutes`, or the
 *     auto-close's `return_priority_until` when that is later) and not after her own close: the
 *     SAME topic goes on — «أهلاً بيكي تاني، بنكمل في …» and the model sees the whole chat.
 *   - Otherwise: a FRESH start — the old flow/collect/router memory is gone, the model no longer
 *     reads the closed messages as if they were still going on, the reply carries «القائمة», and
 *     the bot still knows her recent requests (cases) and the end of the closed chat (CONTEXT).
 *
 * `bot_state.episode` while a close waits: {closed_at, kind, topic, last_id}; once her next message
 * applied it: {mode: continue|fresh, at_id, from_id?, memory?, topic, closed_at, kind}.
 */
final class ConversationEpisode
{
    public const KEY = 'episode';

    public const STAFF = 'staff';

    public const BOT = 'bot';

    public const CUSTOMER = 'customer';

    /** «رجوع للبوت»: the team hands the chat back to the bot in the middle of it — not a goodbye. */
    public const HANDBACK = 'handback';

    /**
     * Round 4 (2026-10-24): a fresh start whose first message only MIGHT be about a request she still has
     * open — «بتسألي عن طلب المرتجع #812 (أوردر #209129)؟» is asked first (begin() returns ASK; the
     * yes/no is FlowState::confirm = CONFIRM, answered by answerRelates()).
     */
    public const ASK = 'ask';

    public const CONFIRM = 'episode_relates';

    /** After her own close, a follow-up within this many minutes still carries on quietly (review I3). */
    public const CUSTOMER_GRACE_MINUTES = 10;

    /** Fallback reopen window when the queue has no settings row (minutes). */
    public const DEFAULT_REOPEN_MINUTES = 30;

    /** Cases she opened in the last days that the bot keeps knowing about after a fresh start. */
    private const MEMORY_DAYS = 30;

    /** Words a «that's all, thanks» message is made of (normalized). */
    private const CLOSE_WORDS = ['لا', 'لاء', 'لأ', 'تمام', 'كده', 'كدا', 'خلاص', 'بس', 'مفيش', 'حاجه', 'تانيه', 'شكرا', 'شكرًا', 'متشكره', 'متشكرة', 'مرسي', 'ميرسي',
        'تسلمي', 'تسلم', 'يا', 'قمر', 'حبيبتي', 'جزاكي', 'جزاكم', 'الله', 'خير', 'ربنا', 'يخليكي', 'اوك', 'ok', 'okay', 'no', 'thanks', 'thank', 'you', 'خلصت', 'اوي', 'جدا', 'كتير', 'ليكي', 'ليكم', 'و'];

    /** At least one of these makes it a close (a bare «تمام» or «لا» is an answer, not a goodbye). */
    private const THANKS_WORDS = ['شكرا', 'متشكره', 'متشكرة', 'مرسي', 'ميرسي', 'تسلمي', 'تسلم', 'جزاكي', 'جزاكم', 'يخليكي', 'thanks', 'thank'];

    private const DONE_PAIRS = [['لا', 'تمام'], ['لا', 'كده'], ['لا', 'كدا'], ['لا', 'خلاص'], ['لا', 'مفيش'], ['لاء', 'تمام'], ['لاء', 'كده'], ['no', 'thanks']];

    private const MAX_CLOSE_WORDS = 7;

    public function __construct(
        private readonly OutboundService $outbound,
        private readonly FlowPrompter $prompter,
        private readonly ArabicNormalizer $normalizer = new ArabicNormalizer,
        private readonly OpenRequests $requests = new OpenRequests,
    ) {}

    /** The marker a close leaves: when, who closed, about what, and the last message it covers. */
    public static function marker(Conversation $c, string $kind, ?string $topic = null): array
    {
        // Resolve writes the marker twice (review M7): the second pass no longer sees the topic.
        $pending = ($c->bot_state ?? [])[self::KEY] ?? null;
        $topic ??= self::topicOf($c) ?? (is_array($pending) && ! isset($pending['mode']) ? ($pending['topic'] ?? null) : null);

        return [
            'closed_at' => now()->toIso8601String(),
            'kind' => $kind,
            'topic' => $topic,
            'last_id' => (int) ($c->exists ? $c->messages()->max('id') : 0),
        ];
    }

    /** Records a close on the chat's bot state (the bot's own end of a request, or hers). */
    public static function markClosed(Conversation $c, string $kind, ?string $topic = null): void
    {
        if (! $c->exists) {
            return;
        }

        $c->forceFill(['bot_state' => array_merge($c->bot_state ?? [], [self::KEY => self::marker($c, $kind, $topic)])])->save();
    }

    /**
     * «طلب مرتجع #812 (أوردر #209129)»: the request she was on — the newest case of this chat from
     * the last day, else the topic the team was handed, else null (nothing to summarise).
     */
    public static function topicOf(Conversation $c, ?string $fallback = null): ?string
    {
        $case = $c->exists ? SupportCase::query()->where('conversation_id', $c->id)
            ->where('created_at', '>=', now()->subDay())->latest('id')->first() : null;

        if ($case !== null) {
            return self::caseLabel($case);
        }

        $topic = trim((string) ($c->getOriginal('handover_topic') ?? $c->handover_topic ?? ''));

        return $topic !== '' ? $topic : $fallback;
    }

    /**
     * Her message closes the chat herself: a short «لا تمام شكرا» / «شكرا يا قمر» / «لأ كده تمام»,
     * made only of thanks and «that's all» words, with a thanks word or a «no + done» pair in it.
     */
    public function isCustomerClose(string $text): bool
    {
        $norm = trim(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $this->normalizer->normalize($text)) ?? '');
        $words = preg_split('/\s+/u', $norm, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === [] || count($words) > self::MAX_CLOSE_WORDS || str_contains($text, '?') || str_contains($text, '؟')) {
            return false;
        }

        $allowed = array_map(fn ($w) => $this->normalizer->normalize($w), self::CLOSE_WORDS);

        foreach ($words as $w) {
            if (! in_array($w, $allowed, true)) {
                return false;
            }
        }

        $thanks = array_map(fn ($w) => $this->normalizer->normalize($w), self::THANKS_WORDS);

        if (array_intersect($words, $thanks) !== []) {
            return true;
        }

        foreach (self::DONE_PAIRS as [$no, $done]) {
            if (in_array($this->normalizer->normalize($no), $words, true) && in_array($this->normalizer->normalize($done), $words, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nothing is waiting for an answer: no flow (the main menu on screen does not count — «شكرا» at
     * the menu is a goodbye, not a missed choice), no yes/no offer, no «محتاجة إيه؟», no next request.
     */
    public static function idle(Conversation $c): bool
    {
        $flow = FlowState::flow($c);

        return ($flow === null || $flow['key'] === ConversationRouter::MAIN_MENU) && FlowState::confirm($c) === null && ! HumanHandover::pending($c) && TaskQueue::nextPending($c) === null;
    }

    /**
     * The first turn after a close applies the rule: `continue` (the same topic, with its summary
     * line sent here) or `fresh` (the old state dropped, the history floor set). Null when there
     * is no close waiting, or something is still waiting for her answer.
     */
    public function begin(Conversation $c, Collection $burst): ?string
    {
        $state = $c->bot_state ?? [];
        $episode = $state[self::KEY] ?? null;
        $first = $burst->first();

        // Applied once (review C1): a marker that already has its `mode` is the conversation going on.
        if (! is_array($episode) || ! isset($episode['closed_at']) || isset($episode['mode']) || ! $first instanceof Message
            || (int) $first->id <= (int) ($episode['last_id'] ?? 0) || ! self::idle($c)) {
            return null;
        }

        $closedAt = rescue(fn () => Carbon::parse((string) $episode['closed_at']), null, report: false) ?? now();
        $kind = (string) ($episode['kind'] ?? self::STAFF);
        $topic = is_string($episode['topic'] ?? null) && trim($episode['topic']) !== '' ? trim($episode['topic']) : null;
        $applied = ['closed_at' => $closedAt->toIso8601String(), 'kind' => $kind, 'topic' => $topic, 'at_id' => (int) $burst->last()->id];

        // Her own «شكرا» followed by a follow-up a few minutes later is the same chat (review I3).
        // A sealed close (she rated it, owner 2026-10-19) starts fresh whatever the window.
        $within = empty($episode['sealed']) && $kind === self::CUSTOMER
            && now()->lessThanOrEqualTo($closedAt->copy()->addMinutes(self::CUSTOMER_GRACE_MINUTES));

        $within = $within || (empty($episode['sealed']) && $kind !== self::CUSTOMER && now()->lessThanOrEqualTo($this->reopenUntil($c, $closedAt)));

        if ($within) {
            $c->forceFill(['bot_state' => array_merge($state, [self::KEY => ['mode' => 'continue'] + $applied])])->save();

            // «أهلاً بيكي تاني» only when the team closed it — after the bot's own end, her «شكرا» or a
            // hand-back she never left (review I4).
            if ($topic !== null && $kind === self::STAFF) {
                $this->send($c, str_replace('{topic}', $topic, $this->script('episode_continue')));
            }

            return 'continue';
        }

        // A fresh start: only the burst marker (and the delay apology flag) survive, like a team reset.
        $kept = array_intersect_key($state, array_flip(['last_turn_message_id', 'delayed_response_sent']));
        $memory = $this->memory($c, $closedAt, $topic, (int) $first->id);
        // Round 4 (the owner): FIRST her open requests — is this message about one of them?
        $relates = $this->relates($c, $burst);
        $c->forceFill(['bot_state' => $kept + [self::KEY => ['mode' => 'fresh', 'from_id' => (int) $first->id, 'memory' => $memory, 'relates' => $relates] + $applied]])->save();

        if ($relates['decision'] === self::ASK) {
            $this->ask($c, $relates);

            return self::ASK;
        }

        return 'fresh';
    }

    /**
     * Whether the first message of a fresh start speaks about a request she still has open (OpenRequests):
     * `case` (answer in its light), `ask` (only likely: «بتسألي عن …؟» first) or `none` (a new matter).
     *
     * @return array{decision:string, strength:string, case_id:?int, order_number:?string, label:?string, detail:?string, pending_ids:list<int>, confirmed:bool}
     */
    private function relates(Conversation $c, Collection $burst): array
    {
        $none = ['decision' => 'none', 'strength' => 'none', 'case_id' => null, 'order_number' => null, 'label' => null, 'detail' => null, 'pending_ids' => [], 'confirmed' => false];
        $text = $burst->map(fn (Message $m) => trim((string) $m->body))->filter()->implode("\n");

        if ($text === '' || $burst->contains(fn (Message $m) => filled($m->payload))) {
            return $none;
        }

        $found = rescue(fn () => $this->requests->relate($text, OpenRequests::candidates($c)), ['strength' => 'none', 'request' => null], report: true);
        $request = $found['request'] ?? null;

        if (! is_array($request)) {
            return $none;
        }

        return [
            'decision' => $found['strength'] === 'strong' ? 'case' : self::ASK,
            'strength' => (string) $found['strength'],
            'case_id' => $request['kind'] === 'case' ? (int) $request['id'] : null,
            'order_number' => $request['order_number'] ?? null,
            'label' => (string) $request['label'],
            'detail' => (string) ($request['detail'] ?? ''),
            'pending_ids' => $burst->map(fn (Message $m) => (int) $m->id)->all(),
            'confirmed' => false,
        ];
    }

    /** «بتسألي عن طلب المرتجع #812 (أوردر #209129)؟» [أيوه] [لأ] — her message waits for the answer. */
    private function ask(Conversation $c, array $relates): void
    {
        $this->send($c, str_replace('{request}', (string) $relates['label'], $this->script('episode_relates_ask')), [FlowLabels::YES_BUTTON, FlowLabels::NO_BUTTON]);
        FlowState::setConfirm($c, self::CONFIRM);
    }

    /**
     * Her yes/no to «بتسألي عن طلب …؟»: the decision is kept on the episode (the model reads it in CONTEXT)
     * and the message she sent before the question is answered now, as one turn.
     */
    public function answerRelates(Conversation $c, bool $yes): bool
    {
        FlowState::setConfirm($c, null);
        $state = $c->bot_state ?? [];
        $episode = is_array($state[self::KEY] ?? null) ? $state[self::KEY] : [];
        $relates = (array) ($episode['relates'] ?? []);
        $pending = array_map('intval', (array) ($relates['pending_ids'] ?? []));
        $relates['decision'] = $yes ? 'case' : 'none';
        $relates['confirmed'] = $yes;
        $relates['declined'] = ! $yes;
        $relates['pending_ids'] = [];
        $episode['relates'] = $relates;
        $c->forceFill(['bot_state' => array_merge($state, [self::KEY => $episode])])->save();

        $messages = $pending !== [] ? Message::query()->whereKey($pending)->where('conversation_id', $c->id)->orderBy('id')->get() : collect();

        if ($messages->isEmpty()) {
            app(FlowEngine::class)->start($c, ConversationRouter::MAIN_MENU);

            return true;
        }

        app(BotEngine::class)->handleTurn($c->refresh(), $messages);

        return true;
    }

    /**
     * Her rating after a close seals it (owner 2026-10-19): the chat is over for her, so her next
     * message starts fresh — the greeting and the menu — whatever the reopen window says. Nothing
     * to seal once the close was already applied by a later message.
     */
    public static function seal(Conversation $c): void
    {
        $state = $c->bot_state ?? [];
        $episode = $state[self::KEY] ?? null;

        if (! is_array($episode) || ! isset($episode['closed_at']) || isset($episode['mode'])) {
            return;
        }

        $c->forceFill(['bot_state' => array_merge($state, [self::KEY => $episode + ['sealed' => true]])])->save();
    }

    /** The end of the reopen window for a close at $closedAt. */
    public function reopenUntil(Conversation $c, Carbon $closedAt): Carbon
    {
        $minutes = rescue(fn () => QueueSetting::current()->reopen_after_done_minutes, null, report: false);
        $until = $closedAt->copy()->addMinutes(max(0, (int) ($minutes ?? self::DEFAULT_REOPEN_MINUTES)));

        // The queue's own return window after an auto-close (C1) is longer: the bot keeps to it too.
        if ($c->return_priority_until !== null && $c->return_priority_until->greaterThan($until)) {
            return Carbon::instance($c->return_priority_until);
        }

        return $until;
    }

    /** The first message id the model may read as this conversation (fresh start), or null. */
    public static function historyFloor(Conversation $c): ?int
    {
        $episode = ($c->bot_state ?? [])[self::KEY] ?? null;

        return is_array($episode) && ($episode['mode'] ?? null) === 'fresh' && is_numeric($episode['from_id'] ?? null) ? (int) $episode['from_id'] : null;
    }

    /** When the closed chat ended, for a fresh start (the turn limit counts from there), else null. */
    public static function freshSince(Conversation $c): ?Carbon
    {
        $episode = ($c->bot_state ?? [])[self::KEY] ?? null;

        if (! is_array($episode) || ($episode['mode'] ?? null) !== 'fresh') {
            return null;
        }

        return rescue(fn () => Carbon::parse((string) $episode['closed_at']), null, report: false);
    }

    /** The CONTEXT lines the model reads about the close (both routers' agent calls). */
    public static function context(Conversation $c): ?string
    {
        $episode = ($c->bot_state ?? [])[self::KEY] ?? null;

        if (! is_array($episode) || ! isset($episode['mode'])) {
            return null;
        }

        $ago = self::ago((string) ($episode['closed_at'] ?? ''));
        $topic = is_string($episode['topic'] ?? null) ? $episode['topic'] : null;

        if ($episode['mode'] === 'continue') {
            // Only on the turn that reopened it: later turns are simply the conversation going on.
            if ((int) ($episode['at_id'] ?? 0) !== (int) (($c->bot_state ?? [])['last_turn_message_id'] ?? -1)) {
                return null;
            }

            return '- she came back '.$ago.' after this chat was closed'.($topic !== null ? ' (topic: '.$topic.')' : '').': this continues that same conversation, answer in its light.';
        }

        $relates = (array) ($episode['relates'] ?? []);
        $label = (string) ($relates['label'] ?? '');
        $detail = trim((string) ($relates['detail'] ?? ''));
        $about = match ($relates['decision'] ?? null) {
            'case' => '- her message is about her open request: '.$label.($detail !== '' ? ' ('.$detail.')' : '').(! empty($relates['confirmed']) ? ' — she confirmed it' : '')
                .'. Answer in its light: the team already has this request, so say what happens next from KNOWLEDGE, never open a new request for it, never promise a date; hand over when KNOWLEDGE does not say.',
            'none' => ! empty($relates['declined']) && $label !== '' ? '- she said her message is not about her earlier request ('.$label.'): treat it as a new question.' : null,
            default => null,
        };

        return trim("- new conversation: her previous chat was closed {$ago}".($topic !== null ? " (topic: {$topic})" : '').'. Do not carry on that topic unless she brings it up herself.'
            ."\n".(string) ($episode['memory'] ?? '').($about !== null ? "\n".$about : ''));
    }

    /** What the bot still knows after a fresh start: her recent requests and the end of the closed chat. */
    private function memory(Conversation $c, Carbon $closedAt, ?string $topic, int $firstId): string
    {
        $cases = SupportCase::query()
            ->where(fn ($q) => $q->where('conversation_id', $c->id)->when($c->customer_id, fn ($q) => $q->orWhere('customer_id', $c->customer_id)))
            ->where('created_at', '>=', now()->subDays(self::MEMORY_DAYS))
            ->latest('id')->limit(3)->get();

        $lines = [];

        if ($cases->isNotEmpty()) {
            $lines[] = '- her recent requests (system records; mention only if relevant): '.$cases
                ->map(fn (SupportCase $s) => self::caseLabel($s).' — '.$s->status.', '.$s->created_at?->toDateString())->implode('; ');
        }

        $tail = $c->messages()->where('id', '<', $firstId)
            ->whereIn('sender_type', [SenderType::Customer->value, SenderType::Bot->value, SenderType::User->value])
            ->whereNotNull('body')->orderByDesc('id')->limit(2)->get()->reverse()
            ->map(fn (Message $m) => ($m->sender_type === SenderType::Customer ? 'her' : 'the store').': «'.mb_substr(trim((string) $m->body), 0, 160).'»')
            ->filter()->implode(' / ');

        if ($tail !== '') {
            $lines[] = '- the end of the closed chat: '.$tail;
        }

        return implode("\n", $lines);
    }

    private static function caseLabel(SupportCase $case): string
    {
        $type = trans('cases.types.'.$case->type, [], 'ar');
        $type = is_string($type) && ! str_starts_with($type, 'cases.') ? $type : FlowLabels::of((string) $case->type);
        $order = filled($case->order_number) ? ' (أوردر #'.ltrim((string) $case->order_number, '#').')' : '';

        return 'طلب '.$type.' #'.$case->id.$order;
    }

    private static function ago(string $at): string
    {
        $minutes = (int) (rescue(fn () => Carbon::parse($at)->diffInMinutes(now(), absolute: true), 0, report: false));

        return match (true) {
            $minutes < 60 => $minutes.' minutes ago',
            $minutes < 48 * 60 => intdiv($minutes, 60).' hours ago',
            default => intdiv($minutes, 1440).' days ago',
        };
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
            Log::info('episode.window_closed', ['conversation_id' => $c->id]);
        }
    }
}
