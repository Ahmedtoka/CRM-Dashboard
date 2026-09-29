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
 * `request_line` is the first line of what she asked for, from the bot's summary.
 *
 * @mixin QueueEntry
 */
class QueueEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return self::data($this->resource);
    }

    /**
     * The ticket as the board and the inbox read it. Pass the settings when many entries are
     * shaped in one go (the board snapshot), so they are not read once per entry; the
     * conversation and its customer should be eager loaded for the same reason.
     *
     * @return array<string, mixed>
     */
    public static function data(QueueEntry $e, ?QueueSetting $settings = null): array
    {
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
            'request_line' => self::requestLine($e),
            'rule' => $e->rule,
            'close_reason' => $e->close_reason,
            'silence_left_seconds' => self::silenceLeft($e, $settings),
            'silence_warned' => $e->isOpen() && $e->silence_warned_at !== null,
            'return_priority_until' => $e->return_priority_until?->toIso8601String(),
            'reopened_from_entry_id' => $e->reopened_from_entry_id,
        ];
    }

    public static function silenceLeft(QueueEntry $e, ?QueueSetting $settings = null): ?int
    {
        if (! $e->isOpen()) {
            return null;
        }

        $idle = WindowLifecycle::idleSeconds($e);

        if ($idle === null) {
            return null;
        }

        return max(0, (int) ($settings ?? QueueSetting::current())->silence_close_seconds - $idle);
    }

    /** The topic the bot understood, else the first line of its summary; at most 120 characters. */
    public static function requestLine(QueueEntry $e): ?string
    {
        $summary = is_array($e->bot_summary) ? $e->bot_summary : [];
        $lines = is_array($summary['lines'] ?? null) ? $summary['lines'] : [];
        $first = collect([$summary['topic'] ?? null, ...array_values($lines)])
            ->first(fn ($line) => is_string($line) && trim($line) !== '');

        return $first === null ? null : mb_substr(trim($first), 0, 120);
    }
}
