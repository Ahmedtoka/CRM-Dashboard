<?php

namespace App\Analytics;

use App\Channels\Ads\AdSpend;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Campaign → conversations → orders → spend (owner, 2026-09-25). A conversation counts for the
 * campaign its first ad belongs to (conversations.ad_campaign_name, else the ad title); an order
 * counts when its customer has such a conversation whose ad touch came before the order was
 * placed (or the order was placed from the conversation itself), within the period. Spend comes
 * from the Marketing API insights of the Facebook page's token and is matched on the campaign
 * name; campaigns with spend and no conversation are listed too, so nothing spent is hidden.
 */
class AdsReport
{
    public const UNNAMED = 'unnamed';

    public function __construct(private readonly AdSpend $spend) {}

    /**
     * @return array{rows:list<array<string, mixed>>, totals:array<string, mixed>, currency:?string, spend_available:bool}
     */
    public function build(CarbonInterface $from, CarbonInterface $to, ?Platform $platform = null): array
    {
        $conversations = Conversation::query()
            ->whereNotNull('ad_attributed_at')
            ->whereBetween('ad_attributed_at', [$from, $to])
            ->when($platform !== null, fn ($q) => $q->where('platform', $platform->value))
            ->get(['id', 'customer_id', 'platform', 'ad_id', 'ad_title', 'ad_campaign_name', 'ad_attributed_at']);

        $byCampaign = $conversations->groupBy(fn (Conversation $c) => $this->campaignOf($c));
        $orders = $this->orders($conversations, $from, $to);

        $rows = $byCampaign->map(function (Collection $group, string $campaign) use ($orders) {
            $customerIds = $group->pluck('customer_id')->filter()->unique();
            $mine = $orders->filter(fn (array $o) => $o['campaign'] === $campaign);

            return [
                'campaign' => $campaign,
                'ads' => $group->pluck('ad_title')->filter()->unique()->values()->all(),
                'conversations' => $group->count(),
                'customers' => $customerIds->count(),
                'orders' => $mine->count(),
                'revenue' => round((float) $mine->sum('total'), 2),
                'spend' => null,
            ];
        })->values();

        $spend = $this->spendRows($from, $to);
        $currency = collect($spend)->pluck('currency')->filter()->first();

        $rows = $rows->map(function (array $row) use (&$spend) {
            if (isset($spend[$row['campaign']])) {
                $row['spend'] = $spend[$row['campaign']]['spend'];
                unset($spend[$row['campaign']]);
            }

            return $row;
        });

        // Campaigns that spent money and brought no conversation in the period.
        foreach ($spend as $name => $s) {
            $rows->push(['campaign' => $name, 'ads' => [], 'conversations' => 0, 'customers' => 0, 'orders' => 0, 'revenue' => 0.0, 'spend' => $s['spend']]);
        }

        $rows = $rows->map(fn (array $r) => $r + [
            'cost_per_conversation' => $r['spend'] !== null && $r['conversations'] > 0 ? round($r['spend'] / $r['conversations'], 2) : null,
            'cost_per_order' => $r['spend'] !== null && $r['orders'] > 0 ? round($r['spend'] / $r['orders'], 2) : null,
            'roas' => $r['spend'] !== null && $r['spend'] > 0 ? round($r['revenue'] / $r['spend'], 2) : null,
        ])->sortByDesc(fn (array $r) => [$r['conversations'], $r['spend'] ?? 0])->values();

        $totals = [
            'conversations' => (int) $rows->sum('conversations'),
            'customers' => (int) $conversations->pluck('customer_id')->filter()->unique()->count(),
            'orders' => (int) $rows->sum('orders'),
            'revenue' => round((float) $rows->sum('revenue'), 2),
            'spend' => $rows->contains(fn ($r) => $r['spend'] !== null) ? round((float) $rows->sum(fn ($r) => (float) ($r['spend'] ?? 0)), 2) : null,
        ];
        $totals['roas'] = $totals['spend'] !== null && $totals['spend'] > 0 ? round($totals['revenue'] / $totals['spend'], 2) : null;
        $totals['cost_per_order'] = $totals['spend'] !== null && $totals['orders'] > 0 ? round($totals['spend'] / $totals['orders'], 2) : null;

        return ['rows' => $rows->all(), 'totals' => $totals, 'currency' => $currency, 'spend_available' => $totals['spend'] !== null];
    }

    private function campaignOf(Conversation $c): string
    {
        return $c->ad_campaign_name ?: ($c->ad_title ?: ($c->ad_id ? '#'.$c->ad_id : self::UNNAMED));
    }

    /**
     * Confirmed orders of the attributed customers placed after their ad touch, each tagged with the
     * campaign of that touch (the latest touch before the order wins).
     *
     * @param  Collection<int, Conversation>  $conversations
     * @return Collection<int, array{id:int, total:float, campaign:string}>
     */
    private function orders(Collection $conversations, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $customerIds = $conversations->pluck('customer_id')->filter()->unique()->values();

        if ($customerIds->isEmpty()) {
            return collect();
        }

        $touches = $conversations->groupBy('customer_id');
        $byConversation = $conversations->keyBy('id');

        return Order::query()
            ->whereIn('status', [OrderStatus::Confirmed->value, OrderStatus::AwaitingPayment->value])
            ->where(fn ($q) => $q->whereIn('customer_id', $customerIds)->orWhereIn('conversation_id', $conversations->pluck('id')))
            ->whereBetween('placed_at', [$from, $to])
            ->get(['id', 'customer_id', 'conversation_id', 'total', 'placed_at'])
            ->map(function (Order $o) use ($touches, $byConversation) {
                $touch = $o->conversation_id !== null && isset($byConversation[$o->conversation_id])
                    ? $byConversation[$o->conversation_id]
                    : ($touches[$o->customer_id] ?? collect())
                        ->filter(fn (Conversation $c) => $o->placed_at === null || $c->ad_attributed_at <= $o->placed_at)
                        ->sortByDesc('ad_attributed_at')
                        ->first();

                return $touch === null ? null : ['id' => (int) $o->id, 'total' => (float) $o->total, 'campaign' => $this->campaignOf($touch)];
            })
            ->filter()
            ->values();
    }

    /** @return array<string, array{campaign_id:string, campaign_name:string, spend:float, currency:?string}> */
    private function spendRows(CarbonInterface $from, CarbonInterface $to): array
    {
        $account = ChannelAccount::query()->where('platform', Platform::Facebook->value)->where('driver', 'live')->where('status', 'connected')->orderBy('id')->first();

        return $account !== null ? $this->spend->byCampaign($account, $from, $to) : [];
    }
}
