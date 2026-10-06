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
