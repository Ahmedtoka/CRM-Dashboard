<?php

namespace App\Confirmations\Ui;

use App\Confirmations\ConfirmationSettings;
use App\Enums\OrderConfirmationStatus as S;
use App\Http\Support\ModeratorScope;
use App\Models\OrderConfirmation;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the team sees of WhatsApp order confirmations (spec 2026-10-16, W4): the «تأكيدات الأوردرات» page,
 * its counters, the «النهارده» card and the block on the order page. A day is the confirmations made that
 * Cairo day (the order's webhook), so every counter opens exactly the rows it counts.
 */
final class ConfirmationBoard
{
    /** The page tabs and the statuses each one lists (`all` lists every status). */
    public const TABS = [
        'all' => [],
        'needs_call' => [S::NoResponse],
        'requests' => [S::CancelRequested, S::EditRequested, S::CalledCancelled],
        'confirmed' => [S::Confirmed, S::CalledConfirmed],
        'skipped' => [S::Skipped, S::Failed],
    ];

    /**
     * Tabs that ignore the chosen day: no response comes a day after the send, so the call list is every
     * confirmation still waiting for a call, whatever day its order came in.
     */
    public const ALL_DAYS_TABS = ['needs_call'];

    /**
     * Statuses staff may record an answer on (CallOutcome): the call list, plus an answer the customer gave in her
     * own words (still `sent`, or an edit request settled by talking) and a later try after «مابتردش».
     */
    public const RECORDABLE = [S::NoResponse, S::Sent, S::EditRequested, S::Unreachable];

    public static function canRecord(OrderConfirmation $c): bool
    {
        return in_array($c->status, self::RECORDABLE, true);
    }

    public function __construct(private readonly ConfirmationSettings $settings) {}

    /**
     * Confirmations whose order the viewer may see (OrderPolicy::view / ModeratorScope::orders): supervisors and
     * admins see all, a moderator her platforms' orders and the ones she made.
     *
     * @return Builder<OrderConfirmation>
     */
    public function visibleTo(User $u): Builder
    {
        $q = OrderConfirmation::query();

        return $u->isSupervisorOrAbove() ? $q : $q->whereHas('order', fn ($o) => ModeratorScope::orders($o, $u));
    }

    /**
     * @param  Builder<OrderConfirmation>  $q
     * @return Builder<OrderConfirmation>
     */
    public function between(Builder $q, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $q->whereBetween('order_confirmations.created_at', [$from, $to]);
    }

    /**
     * @param  Builder<OrderConfirmation>  $q
     * @return Builder<OrderConfirmation>
     */
    public function inTab(Builder $q, string $tab): Builder
    {
        $statuses = self::TABS[$tab] ?? [];

        return $statuses === [] ? $q : $q->status(...$statuses);
    }

    /**
     * The header counters and the per-tab counts of one query (one grouped read).
     *
     * @param  Builder<OrderConfirmation>  $q
     * @return array{total: int, sent: int, confirmed: int, answered: int, confirmed_share: ?float, cancel: int, edit: int, no_response: int, unreachable: int, waiting: int, skipped: int, tabs: array<string, int>}
     */
    public function counts(Builder $q): array
    {
        $by = (clone $q)->toBase()->reorder()->groupBy('status')->selectRaw('status, count(*) as n')->pluck('n', 'status')->map(fn ($n) => (int) $n);
        $sum = fn (S ...$statuses) => (int) collect($statuses)->sum(fn (S $s) => $by[$s->value] ?? 0);
        $sent = (int) (clone $q)->whereNotNull('sent_at')->count();

        $confirmed = $sum(S::Confirmed, S::CalledConfirmed);
        $cancel = $sum(S::CancelRequested, S::CalledCancelled);
        $edit = $sum(S::EditRequested);
        $answered = $confirmed + $cancel + $edit;

        return [
            'total' => (int) $by->sum(),
            'sent' => $sent,
            'confirmed' => $confirmed,
            'answered' => $answered,
            // Of the customers who answered (button or call), the share who confirmed.
            'confirmed_share' => $answered > 0 ? round($confirmed / $answered, 2) : null,
            'cancel' => $cancel,
            'edit' => $edit,
            'no_response' => $sum(S::NoResponse),
            'unreachable' => $sum(S::Unreachable),
            'waiting' => $sum(S::Scheduled, S::Sent),
            'skipped' => $sum(S::Skipped, S::Failed),
            'tabs' => collect(self::TABS)->map(fn (array $statuses) => $statuses === [] ? (int) $by->sum() : $sum(...$statuses))->all(),
        ];
    }

    /** One table row (the page) and the head of the order-page block. */
    public function row(OrderConfirmation $c): array
    {
        $order = $c->order;

        return [
            'id' => $c->id,
            'status' => $c->status->value,
            'skip_reason' => $c->skip_reason,
            'last_error' => $c->last_error,
            'phone' => $c->phone_e164,
            'answer' => $c->answer,
            'order' => $order === null ? null : [
                'id' => $order->id,
                // Shopify's name, else the order number, else the CRM id (as orderName() on the client).
                'name' => $order->shopify_order_name ?: ($order->order_number ? '#'.ltrim((string) $order->order_number, '#') : '#'.$order->id),
                'total' => (float) $order->total,
                'currency' => $order->currency,
                'customer_name' => $order->customer?->name ?? $order->shipping_name,
            ],
            'send_at' => $c->send_at?->toIso8601String(),
            'sent_at' => $c->sent_at?->toIso8601String(),
            'reminded_at' => $c->reminded_at?->toIso8601String(),
            'answered_at' => $c->answered_at?->toIso8601String(),
            'called_at' => $c->called_at?->toIso8601String(),
            'call_outcome' => $c->call_outcome,
            'caller' => $c->caller?->name,
            'edit_note' => $c->edit_note,
            'case_id' => $c->case_id,
            'conversation_id' => $c->conversation_id,
            'needs_call' => $c->status->needsCall(),
            'can_record' => self::canRecord($c),
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }

    /** The order page block: the row plus its timeline. */
    public function forOrder(OrderConfirmation $c): array
    {
        return $this->row($c) + ['timeline' => $this->timeline($c)];
    }

    /**
     * Steps reached, oldest first: scheduled → sent → reminded → answered | no response → called. A skipped or
     * failed confirmation ends with that step (and its reason).
     *
     * @return list<array{key: string, at: ?string, detail: ?string}>
     */
    public function timeline(OrderConfirmation $c): array
    {
        $at = fn (?CarbonInterface $t) => $t?->toIso8601String();
        $steps = [['key' => 'scheduled', 'at' => $at($c->send_at ?? $c->created_at), 'detail' => null]];

        if ($c->status === S::Skipped) {
            return [['key' => 'skipped', 'at' => $at($c->created_at), 'detail' => $c->skip_reason]];
        }

        if ($c->sent_at !== null) {
            $steps[] = ['key' => 'sent', 'at' => $at($c->sent_at), 'detail' => null];
        }
        if ($c->reminded_at !== null) {
            $steps[] = ['key' => 'reminded', 'at' => $at($c->reminded_at), 'detail' => null];
        }
        if ($c->answered_at !== null) {
            $steps[] = ['key' => 'answered', 'at' => $at($c->answered_at), 'detail' => $c->answer];
        } elseif (in_array($c->status, [S::NoResponse, S::CalledConfirmed, S::CalledCancelled, S::Unreachable], true)) {
            // The no-response moment is not stored: it is the sent time plus the setting.
            $steps[] = ['key' => 'no_response', 'at' => $at($c->sent_at?->copy()->addHours($this->settings->noResponseAfterHours())), 'detail' => null];
        }
        if ($c->called_at !== null) {
            $steps[] = ['key' => 'called', 'at' => $at($c->called_at), 'detail' => $c->call_outcome];
        }
        if ($c->status === S::Failed) {
            $steps[] = ['key' => 'failed', 'at' => $at($c->updated_at), 'detail' => $c->last_error];
        }

        return $steps;
    }
}
