<?php

namespace App\Queue;

use App\Bot\ArabicNormalizer;
use App\Channels\Adapters\WhatsAppAdapter;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\SenderType;
use App\Inbox\OutboundService;
use App\Inbox\WindowClosedException;
use App\Inbox\WindowPolicy;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Queue\Jobs\SendQueueMessage;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The rating after the final close (spec 2026-09-30 §3; addendum 2026-10-01 C3/C4).
 *
 * The question: `review_delay_seconds` after an inquiry / problem close (`RequestRating`), the bot
 * asks `queue_review_ask` with five buttons «1» … «5». They are quick replies on Messenger and
 * Instagram and ONE interactive list on WhatsApp whatever `crm.whatsapp_menu_style` says
 * (`WhatsAppAdapter::STYLE_LIST`); elsewhere the text goes alone. The last message of the
 * conversation is then ours. It is not asked when:
 *  - she wrote a real message since the close (an acknowledgement does not count);
 *  - the conversation is a test;
 *  - the platform's reply window closed;
 *  - the job is more than an hour late;
 *  - the same customer was asked in the last 24 hours.
 *
 * Her answer: a button, or — while the question is still the last thing we said — a lone typed
 * 1–5 (Arabic-Indic digits too, spaces and punctuation around it allowed). Star glyphs and «نجوم»
 * are still understood when a customer types them; nothing we send carries a star. It is stored
 * once on the rated entry (`review_stars`, `reviewed_at`) and thanked with `queue_review_thanks`.
 * `capture()` runs first in the ingest's queue hook (`QueueService::settles()`), which then does
 * not count the message unread and runs neither a queue hook nor the bot. Part 2 moves the
 * answers into `chat_reviews` and scores them.
 */
class RatingService
{
    public const PAYLOAD_PREFIX = 'queue_rating:';

    /** Answers are taken this long after the question; a tap after it is swallowed. */
    public const ANSWER_HOURS = 24;

    /** At most one rating question per customer in this many hours. */
    public const ONCE_PER_HOURS = 24;

    /** A job that runs this long after its due time asks nothing: the moment has passed. */
    public const LATE_AFTER_MINUTES = 60;

    /** Star glyphs a customer may type as her answer (U+2B50, U+2605, U+1F31F). */
    private const STARS = '/\x{2B50}|\x{2605}|\x{1F31F}/u'; // emoji-input

    /** «5 نجوم», «5 stars», a 5 and star glyphs; spaces and punctuation already removed. */
    private const DIGIT_ANSWER = '/^([1-5])(?:(?:\x{2B50}|\x{2605}|\x{1F31F})+|نجوم|نجمات|نجمة|نجمه|stars?)?$/iu'; // emoji-input

    public function __construct(
        private readonly ArabicNormalizer $normalizer,
        private readonly Acknowledgement $acknowledgements,
    ) {}

    /**
     * The five buttons «1» … «5» in that order, so a typed digit is the same position. The
     * `style` key makes WhatsApp send them as one list.
     *
     * @return list<array{title: string, payload: string, style: string}>
     */
    public static function buttons(QueueEntry $e): array
    {
        return array_map(
            fn (int $n) => ['title' => (string) $n, 'payload' => self::PAYLOAD_PREFIX.$e->id.':'.$n, 'style' => WhatsAppAdapter::STYLE_LIST],
            range(1, 5),
        );
    }

    /** When the rating of this close is due: `review_delay_seconds` after it. */
    public static function dueAt(QueueEntry $e, ?QueueSetting $s = null): ?CarbonInterface
    {
        return $e->closed_at?->copy()->addSeconds((int) ($s ?? QueueSetting::current())->review_delay_seconds);
    }

    /** Why this close gets no rating question now; null when it does. */
    public function skipReason(QueueEntry $e, ?QueueSetting $s = null): ?string
    {
        $s ??= QueueSetting::current();
        $c = $e->conversation;

        return match (true) {
            ! $s->enabled => 'queue_off',
            $c === null => 'no_conversation',
            $e->status !== 'closed' || $e->closed_at === null || ! in_array($e->close_reason, QueueEntry::RATED_CLOSE_REASONS, true) => 'not_rated',
            $e->review_requested_at !== null => 'already_asked',
            $e->assigned_user_id === null => 'no_agent',
            // A load-test chat (2026-10-07) is rated like a real one: the team tests the whole close.
            ($e->is_test || (bool) $c->is_test) && ! $c->isLoadTest() => 'test',
            self::dueAt($e, $s)->copy()->addMinutes(self::LATE_AFTER_MINUTES)->isPast() => 'late',
            app(QueueService::class)->activeEntry($c) !== null || $this->wroteSince($c, $e->closed_at) => 'customer_back',
            $this->askedRecently($e) => 'asked_today',
            ! app(WindowPolicy::class)->evaluate($c, SenderType::Bot)->canSendText() => 'window_closed',
            default => null,
        };
    }

    /** Asks for the rating of this close now, when nothing stops it. True when the question went out. */
    public function request(QueueEntry $closed): bool
    {
        $e = QueueEntry::query()->with(['conversation', 'assignee'])->find($closed->id);
        $reason = $e === null ? 'gone' : $this->skipReason($e);
        $text = $reason === null
            ? app(QueueScripts::class)->text('queue_review_ask', ['name' => $this->firstName((string) $e->assignee?->name)])
            : null;
        $reason ??= $text === null ? 'script_off' : null;

        if ($reason !== null) {
            Log::info('queue.rating_skipped', ['entry' => $closed->id, 'reason' => $reason]);

            return false;
        }

        // Claimed before the send: two runs of the job never ask twice.
        if (QueueEntry::query()->whereKey($e->id)->whereNull('review_requested_at')->update(['review_requested_at' => now()]) !== 1) {
            return false;
        }

        try {
            // Plan ruling 12: the one queue text sent with buttons, so not through SendQueueMessage.
            $ask = app(OutboundService::class)->sendBot($e->conversation, $text, 0, false, self::buttons($e));
        } catch (WindowClosedException) {
            // Also an EmptyBotMessageException (a text that was nothing but emoji): no mark is left.
            QueueEntry::query()->whereKey($e->id)->update(['review_requested_at' => null]);
            Log::info('queue.rating_skipped', ['entry' => $e->id, 'reason' => 'window_closed']);

            return false;
        } catch (Throwable $x) {
            QueueEntry::query()->whereKey($e->id)->update(['review_requested_at' => null]); // the job's retry may ask
            throw $x;
        }

        QueueEntry::query()->whereKey($e->id)->update(['review_message_id' => $ask->id]);

        return true;
    }

    /**
     * Inside the ingest transaction, first in `QueueService::settles()`: is this inbound message
     * the answer to a rating question of this conversation? True when it is: stored while it still
     * counts, or swallowed — a tap on a rating button that no longer counts (answered, over a day
     * old) is neither stored nor anybody's request. A message that cannot be an answer (no rating
     * payload, not a lone 1–5) costs no query.
     */
    public function capture(Conversation $c, Message $m): bool
    {
        $payload = trim((string) $m->payload);

        if (str_starts_with($payload, self::PAYLOAD_PREFIX)) {
            return $this->tap($c, $payload);
        }

        if ($payload !== '' || ! empty($m->attachments)) {
            return false; // another button (the bot's), or a picture / voice note: a real message
        }

        $stars = $this->typedStars((string) $m->body);

        if ($stars === null) {
            return false;
        }

        $e = QueueEntry::query()->where('conversation_id', $c->id)->whereNotNull('review_message_id')->whereNull('review_stars')
            ->where('review_requested_at', '>=', now()->subHours(self::ANSWER_HOURS))->tap(fn ($q) => self::withoutFailedAsk($q))
            ->latest('review_requested_at')->first();

        if ($e === null) {
            return false;
        }

        // Typed, it counts only while the question is the last thing we (the bot or a moderator) said.
        $latest = $c->messages()->where('direction', MessageDirection::Out->value)
            ->whereIn('sender_type', [SenderType::Bot->value, SenderType::User->value])->where('id', '<', $m->id)->max('id');

        if ((int) $latest !== (int) $e->review_message_id) {
            return false;
        }

        $this->store($e, $stars);

        return true;
    }

    /**
     * A typed answer → 1–5, anything else → null. A lone digit, Latin or Arabic-Indic, with
     * optional spaces or punctuation («4», «٤», « 5 ! », «٣.»); also a digit with star glyphs or
     * «نجوم» («5 نجوم», a «2» followed by a star), or 1–5 star glyphs alone, as customers may
     * still send them.
     */
    public function typedStars(string $body): ?int
    {
        $t = (string) preg_replace('/[\s\p{P}\x{FE0E}\x{FE0F}\x{200D}]+/u', '', $this->normalizer->digitsToLatin($body));

        if ($t === '') {
            return null;
        }

        if (preg_match(self::DIGIT_ANSWER, $t, $x) === 1) {
            return (int) $x[1];
        }

        $n = (int) preg_match_all(self::STARS, $t);

        return $n >= 1 && $n <= 5 && preg_replace(self::STARS, '', $t) === '' ? $n : null;
    }

    /** A rating button of this conversation: stored while it still counts, else swallowed. */
    private function tap(Conversation $c, string $payload): bool
    {
        if (preg_match('/^'.preg_quote(self::PAYLOAD_PREFIX, '/').'(\d+):([1-5])$/', $payload, $x) !== 1) {
            return false;
        }

        $e = QueueEntry::query()->where('conversation_id', $c->id)->find((int) $x[1]);

        if ($e === null) {
            return false;
        }

        if ($e->review_requested_at !== null && $e->review_stars === null
            && $e->review_requested_at->greaterThanOrEqualTo(now()->subHours(self::ANSWER_HOURS))) {
            $this->store($e, (int) $x[2]);
        }

        return true;
    }

    /**
     * Once per close. The conversation row first (the queue's lock order, plan ruling 20), then a
     * conditional write of the entry; thanked after commit.
     */
    private function store(QueueEntry $e, int $stars): void
    {
        Conversation::query()->whereKey($e->conversation_id)->lockForUpdate()->first(['id']);
        $done = QueueEntry::query()->whereKey($e->id)->whereNull('review_stars')->update(['review_stars' => $stars, 'reviewed_at' => now()]);

        if ($done === 1) {
            SendQueueMessage::dispatch($e->id, 'queue_review_thanks', []);
        }
    }

    /**
     * A real message of hers after the close: not spam, not an acknowledgement. Strictly after:
     * the message she was answered on can share the close's second.
     */
    private function wroteSince(Conversation $c, CarbonInterface $at): bool
    {
        return $c->messages()->where('direction', MessageDirection::In->value)->where('is_spam', false)
            ->where('created_at', '>', $at)->get(['id', 'body', 'attachments', 'payload'])
            ->contains(fn (Message $m) => ! $this->acknowledgements->matches($m));
    }

    /** The same customer (else the same conversation) got a rating question in the last 24 hours. */
    private function askedRecently(QueueEntry $e): bool
    {
        return QueueEntry::query()->whereKeyNot($e->id)
            ->when($e->customer_id !== null, fn ($q) => $q->where('customer_id', $e->customer_id), fn ($q) => $q->where('conversation_id', $e->conversation_id))
            ->where('review_requested_at', '>=', now()->subHours(self::ONCE_PER_HOURS))->tap(fn ($q) => self::withoutFailedAsk($q))
            ->exists();
    }

    /**
     * A failed send leaves no mark, also when the platform fails it later (async): a question
     * whose message ended `failed` never reached her, so it neither counts as asked today nor
     * takes a typed answer. A question still being sent (no message yet) counts.
     *
     * @param  Builder<QueueEntry>  $q
     */
    private static function withoutFailedAsk(Builder $q): void
    {
        $q->whereNotExists(fn ($m) => $m->from('messages')->whereColumn('messages.id', 'queue_entries.review_message_id')
            ->where('messages.status', MessageStatus::Failed->value));
    }

    private function firstName(string $name): string
    {
        $first = trim(explode(' ', trim($name))[0] ?? '');

        return $first !== '' ? $first : trim($name);
    }
}
