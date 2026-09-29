<?php

namespace App\Http\Resources;

use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Queue\WindowLifecycle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One ticket on the live board / waiting hall. `silence_left_seconds` counts down to the
 * auto-close from the moderator's last reply (WindowLifecycle::silentSince(); null while the
 * entry is not open, the moderator has not replied yet or the customer wrote last);
 * `wait_seconds` is the stored wait once called, else the time waited so far.
 *
 * @mixin QueueEntry
 */
class QueueEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var QueueEntry $e */
        $e = $this->resource;
        $c = $e->conversation;
        $customer = $c?->customer;

        return [
            'id' => $e->id,
            'ticket' => $e->ticket_no,
            'status' => $e->status,
            'priority' => $e->priority,
            'kind' => $e->kind,
            'platform' => $c?->platform?->value,
            'customer' => $customer ? ['id' => $customer->id, 'name' => $customer->name, 'avatar_url' => $customer->avatar_url] : null,
            'conversation_id' => $e->conversation_id,
            'assigned_user_id' => $e->assigned_user_id,
            'reserved_user_id' => $e->reserved_user_id,
            'window_no' => $e->window_no,
            'enqueued_at' => $e->enqueued_at?->toIso8601String(),
            'delivered_at' => $e->delivered_at?->toIso8601String(),
            'first_reply_at' => $e->first_reply_at?->toIso8601String(),
            'eta_seconds' => $e->eta_seconds,
            'wait_seconds' => $e->wait_seconds ?? ($e->enqueued_at ? max(0, (int) $e->enqueued_at->diffInSeconds(now())) : null),
            'bot_summary' => $e->bot_summary,
            'rule' => $e->rule,
            'silence_left_seconds' => self::silenceLeft($e),
            'silence_warned' => $e->isOpen() && $e->silence_warned_at !== null,
            'return_priority_until' => $e->return_priority_until?->toIso8601String(),
            'reopened_from_entry_id' => $e->reopened_from_entry_id,
        ];
    }

    public static function silenceLeft(QueueEntry $e): ?int
    {
        if (! $e->isOpen()) {
            return null;
        }

        $idle = WindowLifecycle::idleSeconds($e);

        if ($idle === null) {
            return null;
        }

        return max(0, (int) QueueSetting::current()->silence_close_seconds - $idle);
    }
}
