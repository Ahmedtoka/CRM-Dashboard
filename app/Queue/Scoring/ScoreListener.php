<?php

namespace App\Queue\Scoring;

use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Queue\Events\CloseReversed;
use App\Queue\Events\WindowClosed;

/**
 * What a window earns (queue Part 2, refresh 2026-10-17, rulings N1–N5, N9). Every award goes to
 * the window's assignee on the window's business day and is keyed per entry, so running any of
 * these twice (a retried event, the nightly repair) never pays twice. Test and load-test windows
 * (`is_test`) never earn. Listener bodies are rescued: a scoring failure never touches the close.
 *
 *  - final close (`WindowClosed`): base points by close reason (inquiry, problem, case_open,
 *    auto_close, escalation; 0 = no line) and, for a window closed by hand, the speed bonus from
 *    delivery to her first reply (≤ speed_fast_seconds / ≤ speed_ok_seconds);
 *  - `CloseReversed`: base and speed of that window are taken back;
 *  - no-reply hand-off: − points.no_reply;
 *  - the customer's rating: (stars − 3) × review_per_star.
 */
class ScoreListener
{
    /** Close reason → points key → reason key. */
    private const BASE = [
        'inquiry' => ['inquiry', 'base_inquiry'],
        'problem' => ['problem', 'base_problem'],
        'case' => ['case_open', 'base_case'],
        'auto' => ['auto_close', 'base_auto'],
        'escalation' => ['escalation', 'base_escalation'],
    ];

    /** Closes she handled to the end: these may earn the speed bonus. */
    private const SPEED_REASONS = ['inquiry', 'problem', 'case'];

    public function __construct(private readonly ScoreKeeper $keeper) {}

    public function windowClosed(WindowClosed $event): void
    {
        rescue(fn () => $this->forEntry($event->entry->fresh()), null, report: true);
    }

    public function closeReversed(CloseReversed $event): void
    {
        rescue(fn () => $this->keeper->reverseWhere('queue_entry_id', (int) $event->entry->id, ['base', 'speed'], 'reversed'), null, report: true);
    }

    /** Base + speed of a closed window; idempotent (also the rollup's repair). */
    public function forEntry(?QueueEntry $e): void
    {
        if (! $this->earns($e) || $e->status !== 'closed' || $e->reversed_at !== null) {
            return;
        }

        $s = QueueSetting::current();
        [$pointsKey, $reasonKey] = self::BASE[$e->close_reason] ?? [null, null];

        // Close cycle RC-8: a window she took by answering a bot chat (`claim`) earns its base only
        // when the customer wrote after it, and never the speed bonus (its first reply is the claim).
        $claim = $e->rule === 'claim';
        if ($claim && ($e->last_customer_message_at === null || $e->delivered_at === null || $e->last_customer_message_at->lessThanOrEqualTo($e->delivered_at))) {
            $pointsKey = null;
        }
        // H-16: «البوت يكمّل» with no reply of hers earns nothing.
        if ($e->end_mode === 'bot' && $e->first_reply_at === null) {
            $pointsKey = null;
        }

        if ($pointsKey !== null && ($points = $s->point($pointsKey)) !== 0) {
            $this->award($e, 'base', $points, $reasonKey, [], 'base:'.$e->id.self::seq($e));
        }

        if (! $claim && in_array($e->close_reason, self::SPEED_REASONS, true) && $e->delivered_at !== null && $e->first_reply_at !== null) {
            $secs = max(0, (int) $e->delivered_at->diffInSeconds($e->first_reply_at));
            [$key, $points] = match (true) {
                $secs <= (int) $s->speed_fast_seconds => ['speed_fast', $s->point('speed_fast')],
                $secs <= (int) $s->speed_ok_seconds => ['speed_ok', $s->point('speed_ok')],
                default => [null, 0],
            };

            if ($key !== null && $points !== 0) {
                $this->award($e, 'speed', $points, $key, ['time' => sprintf('%d:%02d', intdiv($secs, 60), $secs % 60)], 'speed:'.$e->id.self::seq($e));
            }
        }
    }

    /** The no-reply hand-off of this (now closed) window. */
    public function noReply(?QueueEntry $e): void
    {
        if ($this->earns($e) && ($points = QueueSetting::current()->point('no_reply')) > 0) {
            $this->award($e, 'no_reply', -$points, 'no_reply', [], 'no_reply:'.$e->id);
        }
    }

    /** The customer's rating of this window (`review_stars` already stored). */
    public function rated(?QueueEntry $e): void
    {
        if (! $this->earns($e) || $e->review_stars === null) {
            return;
        }

        $stars = max(1, min(5, (int) $e->review_stars));
        $points = ($stars - 3) * QueueSetting::current()->point('review_per_star');

        if ($points !== 0) {
            $this->award($e, 'review', $points, 'review', ['stars' => $stars], 'review:'.$e->id);
        }
    }

    /** RC-4: the first close keeps the old key; a close after «تراجع» gets its own. */
    private static function seq(QueueEntry $e): string
    {
        return (int) $e->close_seq > 1 ? ':'.(int) $e->close_seq : '';
    }

    private function earns(?QueueEntry $e): bool
    {
        return $e !== null && ! $e->is_test && $e->assigned_user_id !== null;
    }

    private function award(QueueEntry $e, string $kind, int $points, string $reasonKey, array $vars, string $key): void
    {
        $this->keeper->award((int) $e->assigned_user_id, $kind, $points, $reasonKey, $vars + ['ticket' => $e->ticket_no % 100000], $key, [
            'queue_entry_id' => $e->id, 'conversation_id' => $e->conversation_id,
        ], $e->business_date?->toDateString());
    }
}
