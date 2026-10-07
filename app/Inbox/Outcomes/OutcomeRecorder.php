<?php

namespace App\Inbox\Outcomes;

use App\Ads\Reports\AdsQuery;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\ConversationOutcome;
use App\Models\Message;
use App\Models\Order;
use App\Models\QueueEntry;
use App\Models\User;
use App\Queue\Acknowledgement;
use App\Queue\RatingService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The only writer of `conversation_outcomes` (control room S3, D13). See the S3 plan's
 * Definitions for what an episode, its key and its window are. Never throws into a close: the
 * callers that are not inside a close wrap it in rescue().
 */
final class OutcomeRecorder
{
    /** Handover categories of a service chat, not a sale (C 3.1 `service`, from the handover reason). */
    public const SERVICE_CATEGORIES = [
        'order_status', 'return', 'exchange', 'return_exchange', 'complaint', 'delivery_followup', 'defect', 'late_order',
        'cancel_order', 'edit_order', 'cancel_edit', 'address', 'payment',
        'order_not_found', 'delayed_order', 'order_hold', 'order_returned', 'failed_delivery_attempt',
        'order_details_missing', 'order_verification_failed',
    ];

    /** Queue close reasons that end an episode (a final close or the silence auto-close); reroutes do not. */
    public const ENDING_CLOSE_REASONS = ['inquiry', 'problem', 'case', 'auto'];

    /**
     * The episode running now. Before the first end, the episode window starts at
     * `crm.outcomes.tracking_from` (the deploy date): older messages, orders and handovers never
     * leak into it. A thanks, a sticker or a rating answer never starts an episode.
     *
     * @return array{key:string, first_message_id:?int, started_at:?CarbonInterface, since:CarbonInterface}
     */
    public function episode(Conversation $c): array
    {
        $lastEnded = $this->lastEnded($c);
        $since = $lastEnded?->ended_at ?? $this->trackingFrom();
        $open = $this->currentRow($c);

        if ($open !== null) {
            return ['key' => $open->episode_key, 'first_message_id' => $open->first_message_id, 'started_at' => $open->started_at, 'since' => $since];
        }

        $watermark = (int) ($lastEnded?->last_message_id ?? 0);
        $first = $this->firstRealInbound($c, $watermark, $lastEnded === null ? $since : null);

        return $first !== null
            ? ['key' => 'm'.$first->id, 'first_message_id' => (int) $first->id, 'started_at' => $first->created_at, 'since' => $since]
            : ['key' => 'w'.$watermark, 'first_message_id' => null, 'started_at' => null, 'since' => $since];
    }

    /** Start of `crm.outcomes.tracking_from` (Cairo), as a UTC instant: nothing before it is ever an episode. */
    public function trackingFrom(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) config('crm.outcomes.tracking_from', '2026-10-08'), 'Africa/Cairo')->startOfDay()->utc();
    }

    /** True for a customer message that only acknowledges (thanks, sticker, like) or answers the rating question. */
    public function isAckOrRating(Message $m): bool
    {
        return str_starts_with((string) $m->payload, RatingService::PAYLOAD_PREFIX)
            || app(Acknowledgement::class)->matches($m)
            || app(RatingService::class)->typedStars((string) $m->body) !== null;
    }

    /** The first inbound customer message after the watermark (and `$from`) that is not a thanks or a rating answer. */
    private function firstRealInbound(Conversation $c, int $watermark, ?CarbonInterface $from): ?Message
    {
        $after = $watermark;

        while (true) {
            $batch = Message::query()->where('conversation_id', $c->id)->where('id', '>', $after)
                ->where('direction', MessageDirection::In->value)->where('sender_type', SenderType::Customer->value)
                ->when($from !== null, fn ($q) => $q->where('created_at', '>=', $from))
                ->orderBy('id')->limit(50)->get();

            if ($batch->isEmpty()) {
                return null;
            }

            $real = $batch->first(fn (Message $m) => ! $this->isAckOrRating($m));
            if ($real !== null) {
                return $real;
            }

            $after = (int) $batch->last()->id;
        }
    }

    /** The episode's row while it is still open (an order was placed in it); null otherwise. */
    public function currentRow(Conversation $c): ?ConversationOutcome
    {
        return ConversationOutcome::query()->where('conversation_id', $c->id)->whereNull('ended_at')->latest('id')->first();
    }

    /**
     * `ordered` when an order links to the conversation in this episode, else `service` when the
     * episode's handover is a service one, else null. `$since` comes from episode() when the caller
     * already has it (`$sinceKnown`), so a close reads the last end once.
     */
    public function autoOutcome(Conversation $c, ?QueueEntry $entry = null, ?CarbonInterface $since = null, bool $sinceKnown = false): ?Outcome
    {
        $since = $sinceKnown && $since !== null ? $since : ($this->lastEnded($c)?->ended_at ?? $this->trackingFrom());

        if ($this->episodeOrder($c, $since) !== null) {
            return Outcome::Ordered;
        }

        $category = is_array($entry?->bot_summary) ? ($entry->bot_summary['category'] ?? null) : null;

        if ($category === null && $c->handover_at !== null && ($since === null || $c->handover_at->greaterThan($since))) {
            $category = $c->handover_category;
        }

        return is_string($category) && in_array($category, self::SERVICE_CATEGORIES, true) ? Outcome::Service : null;
    }

    /**
     * An order was placed from this conversation (OrderService::create). The running episode is
     * `ordered` (source auto) and stays open until its end; an order placed after a close and
     * before her next message is credited to the episode that just ended.
     */
    public function orderPlaced(Order $order): ?ConversationOutcome
    {
        $c = $order->conversation_id !== null ? Conversation::query()->find($order->conversation_id) : null;

        if ($c === null) {
            return null;
        }

        $ep = $this->episode($c);
        $lastEnded = $this->lastEnded($c);
        $attributes = ['outcome' => Outcome::Ordered->value, 'note' => null, 'source' => ConversationOutcome::SOURCE_AUTO, 'set_by_id' => null, 'set_at' => now(), 'order_id' => $order->id];

        if ($ep['first_message_id'] === null && $this->currentRow($c) === null && $lastEnded !== null) {
            $lastEnded->forceFill($attributes)->save();

            return $lastEnded;
        }

        try {
            return ConversationOutcome::query()->updateOrCreate(
                ['conversation_id' => $c->id, 'episode_key' => $ep['key']],
                $attributes + ['first_message_id' => $ep['first_message_id'], 'started_at' => $ep['started_at']],
            );
        } catch (UniqueConstraintViolationException) {
            // A close created the row between our read and our insert: update it.
            $row = ConversationOutcome::query()->where('conversation_id', $c->id)->where('episode_key', $ep['key'])->firstOrFail();
            $row->forceFill($attributes)->save();

            return $row;
        }
    }

    /**
     * Ends the running episode (see Definitions). Precedence: ordered > picked > service > the end's
     * own default (no_answer for a silence auto-close; idle: no_answer, or unknown when the customer
     * wrote last and nobody answered) > unknown. Nothing new since the last end: that row is
     * returned untouched (a second resolve changes nothing). Call it inside the close's transaction
     * when there is one.
     */
    public function endEpisode(Conversation $c, ?QueueEntry $entry, ?Outcome $picked, ?string $note, ?User $by, EpisodeEnd $how): ConversationOutcome
    {
        $ep = $this->episode($c);
        $row = ConversationOutcome::query()->where('conversation_id', $c->id)->where('episode_key', $ep['key'])->first();

        if ($row === null && $ep['first_message_id'] === null && ($last = $this->lastEnded($c)) !== null) {
            return $last;
        }

        $auto = $this->autoOutcome($c, $entry, $ep['since'], sinceKnown: true);
        $person = $by !== null && in_array($how, [EpisodeEnd::Close, EpisodeEnd::Resolve, EpisodeEnd::ApiResolve], true);

        [$outcome, $source] = match (true) {
            $auto === Outcome::Ordered => [Outcome::Ordered, ConversationOutcome::SOURCE_AUTO],
            $picked !== null && $picked !== Outcome::Ordered && $picked !== Outcome::Unknown => [$picked, ConversationOutcome::SOURCE_AGENT],
            $auto !== null => [$auto, ConversationOutcome::SOURCE_AUTO],
            $how === EpisodeEnd::AutoClose => [Outcome::NoAnswer, ConversationOutcome::SOURCE_AUTO],
            $how === EpisodeEnd::Idle => [$this->idleOutcome($c), ConversationOutcome::SOURCE_AUTO],
            default => [Outcome::Unknown, $person ? ConversationOutcome::SOURCE_AGENT : ConversationOutcome::SOURCE_AUTO],
        };

        $lastMessageId = (int) Message::query()->where('conversation_id', $c->id)->max('id') ?: null;
        $reached = $entry?->delivered_at !== null || $this->reachedAgent($c, $ep['since']);
        $fill = fn (ConversationOutcome $row, Outcome $outcome, string $source) => $row->forceFill([
            'first_message_id' => $row->first_message_id ?? $ep['first_message_id'],
            'started_at' => $row->started_at ?? $ep['started_at'],
            'outcome' => $outcome->value,
            'note' => $outcome === Outcome::Other && $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 200) : null,
            'source' => $source,
            'set_by_id' => $source === ConversationOutcome::SOURCE_AGENT ? $by?->id : null,
            'set_at' => $outcome === Outcome::Ordered && $row->exists ? $row->set_at : now(),
            'queue_entry_id' => $entry?->id ?? $row->queue_entry_id,
            // Only an `ordered` episode holds an order (an unpaid order placed in it no longer counts once she picks another outcome).
            'order_id' => $outcome === Outcome::Ordered ? ($row->order_id ?? $this->episodeOrder($c, $ep['since'])?->id) : null,
            'last_message_id' => $lastMessageId,
            'ended_at' => now(),
            'ended_by' => $how->value,
            'reached_agent' => $reached,
        ])->save();

        if ($row !== null) {
            $fill($row, $outcome, $source);

            return $row;
        }

        $row = new ConversationOutcome(['conversation_id' => $c->id, 'episode_key' => $ep['key']]);

        try {
            $fill($row, $outcome, $source);
        } catch (UniqueConstraintViolationException) {
            // orderPlaced() inserted this episode's row between our read and our insert (a failed INSERT does
            // not abort the surrounding close transaction on MariaDB): end that row instead. Its order wins.
            $row = ConversationOutcome::query()->where('conversation_id', $c->id)->where('episode_key', $ep['key'])->firstOrFail();
            if ($row->outcome === Outcome::Ordered->value && $row->order_id !== null) {
                [$outcome, $source] = [Outcome::Ordered, ConversationOutcome::SOURCE_AUTO];
            }
            $fill($row, $outcome, $source);
        }

        return $row;
    }

    /**
     * The hourly sweep's end for a chat nobody closed (bot-only, or the queue off and never resolved).
     * Null when there is nothing real to end: no customer message since the last end, only a thanks /
     * sticker / rating answer, or an episode older than `crm.outcomes.tracking_from`.
     */
    public function endIdle(Conversation $c): ?ConversationOutcome
    {
        if ($this->currentRow($c) !== null) {
            return $this->endEpisode($c, null, null, null, null, EpisodeEnd::Idle);
        }

        $ep = $this->episode($c);

        // episode() only starts an episode on a real customer message after tracking_from (no history backfill).
        if ($ep['first_message_id'] === null || $ep['started_at'] === null || $ep['started_at']->lessThan($this->trackingFrom())) {
            return null;
        }

        return $this->endEpisode($c, null, null, null, null, EpisodeEnd::Idle);
    }

    /** Idle: our side spoke last (or the bot holds her) → she stopped answering; she wrote last to a person → we dropped her. */
    private function idleOutcome(Conversation $c): Outcome
    {
        $lastDirection = Message::query()->where('conversation_id', $c->id)->where('sender_type', '!=', SenderType::System->value)
            ->latest('id')->value('direction');
        $lastDirection = $lastDirection instanceof MessageDirection ? $lastDirection->value : $lastDirection;

        return $lastDirection === MessageDirection::In->value && $c->handler?->value === 'human' ? Outcome::Unknown : Outcome::NoAnswer;
    }

    private function reachedAgent(Conversation $c, ?CarbonInterface $since): bool
    {
        if ($c->handover_at !== null && ($since === null || $c->handover_at->greaterThan($since))) {
            return true;
        }

        return QueueEntry::query()->where('conversation_id', $c->id)->whereNotNull('delivered_at')
            ->when($since !== null, fn ($q) => $q->where('delivered_at', '>', $since))->exists();
    }

    private function lastEnded(Conversation $c): ?ConversationOutcome
    {
        return ConversationOutcome::query()->where('conversation_id', $c->id)->whereNotNull('ended_at')->latest('ended_at')->latest('id')->first();
    }

    /**
     * The latest real order (AdsQuery::NOT_REAL_STATUSES excluded, as the chat funnel counts them)
     * linked to the conversation in this episode, never one an earlier ended episode already holds
     * (an order placed between a close and her next message is credited once).
     */
    private function episodeOrder(Conversation $c, ?CarbonInterface $since): ?Order
    {
        $held = ConversationOutcome::query()->where('conversation_id', $c->id)
            ->whereNotNull('ended_at')->whereNotNull('order_id')->select('order_id');

        return Order::withLoadTest()->where('conversation_id', $c->id)->whereNotIn('status', AdsQuery::NOT_REAL_STATUSES)
            ->whereNotIn('id', $held)
            ->when($since !== null, fn ($q) => $q->where('created_at', '>', $since))
            ->latest('id')->first(['id', 'created_at']);
    }
}
