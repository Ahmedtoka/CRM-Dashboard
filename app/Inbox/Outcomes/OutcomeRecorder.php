<?php

namespace App\Inbox\Outcomes;

use App\Enums\MessageDirection;
use App\Enums\OrderStatus;
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
     * The episode running now.
     *
     * @return array{key:string, first_message_id:?int, started_at:?CarbonInterface, since:?CarbonInterface}
     */
    public function episode(Conversation $c): array
    {
        $lastEnded = $this->lastEnded($c);
        $since = $lastEnded?->ended_at;
        $open = $this->currentRow($c);

        if ($open !== null) {
            return ['key' => $open->episode_key, 'first_message_id' => $open->first_message_id, 'started_at' => $open->started_at, 'since' => $since];
        }

        $watermark = (int) ($lastEnded?->last_message_id ?? 0);
        $first = Message::query()->where('conversation_id', $c->id)->where('id', '>', $watermark)
            ->where('direction', MessageDirection::In->value)->where('sender_type', SenderType::Customer->value)
            ->orderBy('id')->first(['id', 'created_at']);

        return $first !== null
            ? ['key' => 'm'.$first->id, 'first_message_id' => (int) $first->id, 'started_at' => $first->created_at, 'since' => $since]
            : ['key' => 'w'.$watermark, 'first_message_id' => null, 'started_at' => null, 'since' => $since];
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
        $since = $sinceKnown ? $since : $this->lastEnded($c)?->ended_at;

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

        $row ??= new ConversationOutcome(['conversation_id' => $c->id, 'episode_key' => $ep['key']]);
        $row->forceFill([
            'first_message_id' => $row->first_message_id ?? $ep['first_message_id'],
            'started_at' => $row->started_at ?? $ep['started_at'],
            'outcome' => $outcome->value,
            'note' => $outcome === Outcome::Other && $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 200) : null,
            'source' => $source,
            'set_by_id' => $source === ConversationOutcome::SOURCE_AGENT ? $by?->id : null,
            'set_at' => $outcome === Outcome::Ordered && $row->exists ? $row->set_at : now(),
            'queue_entry_id' => $entry?->id ?? $row->queue_entry_id,
            'order_id' => $row->order_id ?? ($outcome === Outcome::Ordered ? $this->episodeOrder($c, $ep['since'])?->id : null),
            'last_message_id' => (int) Message::query()->where('conversation_id', $c->id)->max('id') ?: null,
            'ended_at' => now(),
            'ended_by' => $how->value,
            'reached_agent' => $entry?->delivered_at !== null || $this->reachedAgent($c, $ep['since']),
        ])->save();

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
        $from = CarbonImmutable::parse((string) config('crm.outcomes.tracking_from', '2026-10-08'), 'Africa/Cairo');

        if ($ep['first_message_id'] === null || $ep['started_at'] === null || $ep['started_at']->lessThan($from)) {
            return null;
        }

        $ack = app(Acknowledgement::class);
        $rating = app(RatingService::class);
        $real = Message::query()->where('conversation_id', $c->id)->where('id', '>=', $ep['first_message_id'])
            ->where('direction', MessageDirection::In->value)->get()
            ->contains(fn (Message $m) => ! $ack->matches($m) && $rating->typedStars((string) $m->body) === null
                && ! str_starts_with((string) $m->payload, RatingService::PAYLOAD_PREFIX));

        return $real ? $this->endEpisode($c, null, null, null, null, EpisodeEnd::Idle) : null;
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

    private function episodeOrder(Conversation $c, ?CarbonInterface $since): ?Order
    {
        return Order::query()->where('conversation_id', $c->id)->where('status', '!=', OrderStatus::Cancelled->value)
            ->when($since !== null, fn ($q) => $q->where('created_at', '>', $since))
            ->latest('id')->first(['id', 'created_at']);
    }
}
