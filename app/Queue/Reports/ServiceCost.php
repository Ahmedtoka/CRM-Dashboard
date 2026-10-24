<?php

namespace App\Queue\Reports;

use App\Models\Order;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

/**
 * «تكلفة الخدمة» (round 2026-10-24): what the team and the bot cost per handled conversation and
 * per order, for a range of Cairo business days. The field names are the contract written in
 * `docs/round-2026-10-24/cost-fields.md` (x5 adds the ad cost per order next to them).
 *
 * - Team cost of the period = Σ over its days of `team_monthly_cost_egp` / days of that month.
 * - A handled conversation = a closed queue window with a handled reason (`QueueEntry::HANDLED_REASONS`),
 *   by its business day, test windows left out. The team cost is split evenly over them.
 * - Bot cost = `bot_runs.cost_usd` of the period (by `created_at`) × `usd_egp_rate`; the bot share
 *   of a handled conversation is the cost of the runs on THAT conversation in the period.
 * - cost per conversation = team share + bot share (averaged over the handled conversations).
 * - An order "from a chat" = an order of the period with a `conversation_id`, or one an «ordered»
 *   outcome points at; its chat's cost = its handled windows × team share + its bot runs. cost per
 *   order = Σ of those / orders.
 * Missing settings never become zero: the figure is null and `missing` names the setting.
 */
class ServiceCost
{
    /** @return array<string, mixed> */
    public function build(string $from, string $to): array
    {
        $s = QueueSetting::current();
        $monthly = $s->team_monthly_cost_egp !== null ? (float) $s->team_monthly_cost_egp : null;
        $rate = $s->usd_egp_rate !== null ? (float) $s->usd_egp_rate : null;

        $days = iterator_to_array(CarbonPeriod::create(CarbonImmutable::parse($from), CarbonImmutable::parse($to)));
        $teamCost = $monthly === null ? null : round(array_sum(array_map(fn ($d) => $monthly / $d->daysInMonth, $days)), 2);

        $windows = DB::table('queue_entries')->where('is_test', false)
            ->whereBetween('business_date', QueueReport::storedBounds($from, $to))
            ->where('status', 'closed')->whereIn('close_reason', QueueEntry::HANDLED_REASONS)
            ->get(['conversation_id']);
        $handled = $windows->count();
        /** @var array<int, int> windows per conversation */
        $perConversation = $windows->countBy(fn ($w) => (int) $w->conversation_id)->all();

        [$start, $end] = self::utcBounds($from, $to);
        $runs = DB::table('bot_runs')->whereBetween('created_at', [$start, $end])
            ->selectRaw('conversation_id, SUM(cost_usd) as usd')->groupBy('conversation_id')->get();
        $botUsd = round((float) $runs->sum('usd'), 4);
        /** @var array<int, float> bot USD per conversation (runs on comments have none) */
        $botByConversation = $runs->whereNotNull('conversation_id')->mapWithKeys(fn ($r) => [(int) $r->conversation_id => (float) $r->usd])->all();
        $botUsdHandled = array_sum(array_intersect_key($botByConversation, $perConversation));

        $teamPer = $handled > 0 && $teamCost !== null ? round($teamCost / $handled, 2) : null;
        $botPer = $handled > 0 && $rate !== null ? round($botUsdHandled * $rate / $handled, 2) : null;

        // Orders of the period that came from a chat, and the chat of each.
        $fromOutcomes = DB::table('conversation_outcomes')->whereNotNull('order_id')->select('order_id');
        $orders = Order::query()->whereBetween('orders.created_at', [$start, $end])
            ->where(fn ($q) => $q->whereNotNull('conversation_id')->orWhereIn('id', $fromOutcomes))
            ->get(['id', 'conversation_id']);
        $viaOutcome = $orders->whereNull('conversation_id')->isEmpty() ? collect() : DB::table('conversation_outcomes')
            ->whereIn('order_id', $orders->whereNull('conversation_id')->pluck('id'))->pluck('conversation_id', 'order_id');
        $orderConversations = $orders->map(fn (Order $o) => (int) ($o->conversation_id ?? $viaOutcome[$o->id] ?? 0))->filter()->unique()->values();
        $ordersChatCost = $teamPer !== null && $rate !== null
            ? round($orderConversations->sum(fn (int $c) => ($perConversation[$c] ?? 0) * $teamPer + ($botByConversation[$c] ?? 0) * $rate), 2)
            : null;

        $missing = array_keys(array_filter(['team_monthly_cost_egp' => $monthly === null, 'usd_egp_rate' => $rate === null]));

        return [
            'from' => $from,
            'to' => $to,
            'days' => count($days),
            'team_monthly_cost_egp' => $monthly,
            'usd_egp_rate' => $rate,
            'team_cost_egp' => $teamCost,
            'handled_conversations' => $handled,
            'team_cost_per_conversation_egp' => $teamPer,
            'bot_cost_usd' => $botUsd,
            'bot_cost_egp' => $rate !== null ? round($botUsd * $rate, 2) : null,
            'bot_conversations' => count($botByConversation),
            'bot_cost_per_bot_conversation_egp' => $rate !== null && $botByConversation !== [] ? round($botUsd * $rate / count($botByConversation), 2) : null,
            'bot_cost_per_conversation_egp' => $botPer,
            'cost_per_conversation_egp' => $teamPer !== null && $botPer !== null ? round($teamPer + $botPer, 2) : null,
            'orders_from_chats' => $orders->count(),
            'orders_chat_cost_egp' => $ordersChatCost,
            'cost_per_order_egp' => $ordersChatCost !== null && $orders->count() > 0 ? round($ordersChatCost / $orders->count(), 2) : null,
            'total_egp' => $teamCost !== null && $rate !== null ? round($teamCost + $botUsd * $rate, 2) : null,
            'missing' => $missing,
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private static function utcBounds(string $from, string $to): array
    {
        return [
            CarbonImmutable::createFromFormat('!Y-m-d', $from, 'Africa/Cairo')->startOfDay()->utc(),
            CarbonImmutable::createFromFormat('!Y-m-d', $to, 'Africa/Cairo')->endOfDay()->utc(),
        ];
    }
}
