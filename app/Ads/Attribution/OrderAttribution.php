<?php

namespace App\Ads\Attribution;

use App\Enums\OrderStatus;
use App\Models\Ad;
use App\Models\AdCampaign;
use App\Models\AdDailyMetric;
use App\Models\Conversation;
use App\Models\ConversationAdReferral;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Resolves orders.ad_id / ad_campaign_id / ad_attribution (spec section 5):
 * utm ad id or ad name, then utm campaign, then the customer's latest inbox ad referral before the order and within
 * crm.ads.inbox_window_days (A4, F-006): conversation_ad_referrals rows, or, for a customer with none, the conversation's
 * ad_attributed_at as the touch time.
 */
final class OrderAttribution
{
    /** @var array<string, array{id:int, campaign:?int}> external id => ad */
    private array $adsByExternal = [];

    /** @var array<int, array{id:int, campaign:?int}> ad id => ad */
    private array $adsById = [];

    /** @var array<string, list<int>> lowercase name => ad ids (ascending) */
    private array $adsByName = [];

    /** @var array<string, int> */
    private array $campaignsByExternal = [];

    /** @var array<string, int> */
    private array $campaignsByName = [];

    /** Days back a campaign-level utm order is re-checked for its ad without --force (R-18). */
    public const UTM_REPROCESS_DAYS = 35;

    /**
     * Returns how many orders got an attribution. Without --force: unattributed and inbox orders, cancelled orders
     * that still carry one, and utm_campaign orders with a utm_content placed in the last UTM_REPROCESS_DAYS days
     * (upgraded only when the content now names a stored ad).
     */
    public function run(CarbonImmutable $from, CarbonImmutable $to, bool $force = false): int
    {
        $this->loadLookups();
        $count = 0;
        $reprocessFrom = CarbonImmutable::now()->subDays(self::UTM_REPROCESS_DAYS);

        Order::query()
            ->whereBetween('placed_at', [$from, $to])
            ->when(! $force, fn ($q) => $q->where(fn ($w) => $w->whereNull('ad_attribution')->orWhere('ad_attribution', 'inbox')
                ->orWhere(fn ($c) => $c->where('status', OrderStatus::Cancelled->value)->whereNotNull('ad_attribution'))
                ->orWhere(fn ($u) => $u->where('ad_attribution', 'utm_campaign')->whereNotNull('utm_content')->where('placed_at', '>=', $reprocessFrom))))
            ->chunkById(500, function (Collection $orders) use (&$count, $force) {
                $touches = $this->touches($orders);
                $spend = $this->spendFor($orders);

                foreach ($orders as $order) {
                    $clear = ['ad_id' => null, 'ad_campaign_id' => null, 'ad_attribution' => null];

                    // Cancelled orders carry no attribution (they never count as real orders).
                    if ($order->status === OrderStatus::Cancelled) {
                        if ($order->ad_attribution !== null || $order->ad_id !== null || $order->ad_campaign_id !== null) {
                            Order::query()->whereKey($order->id)->toBase()->update($clear);
                        }

                        continue;
                    }

                    $result = $this->resolve($order, $touches[$order->customer_id] ?? [], $spend);

                    if ($result === null) {
                        if ($force && $order->ad_attribution !== null) { // the evidence is gone
                            Order::query()->whereKey($order->id)->toBase()->update($clear);
                        }

                        continue;
                    }

                    // Without --force an inbox attribution is only replaced by stronger (utm) evidence, and a
                    // campaign-level utm one only by an ad match.
                    if (! $force && $order->ad_attribution === 'inbox' && $result['ad_attribution'] === 'inbox') {
                        continue;
                    }
                    if (! $force && $order->ad_attribution === 'utm_campaign' && $result['ad_attribution'] !== 'utm_ad') {
                        continue;
                    }

                    Order::query()->whereKey($order->id)->toBase()->update($result);
                    $count++;
                }
            });

        return $count;
    }

    private function loadLookups(): void
    {
        $this->adsByExternal = $this->adsById = $this->adsByName = $this->campaignsByExternal = $this->campaignsByName = [];

        foreach (Ad::query()->orderBy('id')->get(['id', 'external_id', 'ad_campaign_id', 'name']) as $ad) {
            $row = ['id' => (int) $ad->id, 'campaign' => $ad->ad_campaign_id !== null ? (int) $ad->ad_campaign_id : null];
            $this->adsById[$row['id']] = $row;
            $this->adsByExternal[(string) $ad->external_id] ??= $row;
            if (filled($ad->name)) {
                $this->adsByName[mb_strtolower(trim($ad->name))][] = $row['id'];
            }
        }

        foreach (AdCampaign::query()->orderBy('id')->get(['id', 'external_id', 'name']) as $c) {
            $this->campaignsByExternal[(string) $c->external_id] ??= (int) $c->id;
            if (filled($c->name)) {
                $this->campaignsByName[mb_strtolower(trim($c->name))] ??= (int) $c->id;
            }
        }
    }

    /**
     * Inbox touches of the chunk's customers, newest first, from placed_at − window of the earliest order to the
     * latest order: the referral history; for a customer with no referral row at all, the conversation's first ad.
     *
     * @return array<int, list<array{ad:string, at:CarbonImmutable}>>
     */
    private function touches(Collection $orders): array
    {
        $ids = $orders->pluck('customer_id')->filter()->unique()->values();
        $placed = $orders->pluck('placed_at')->filter();
        if ($ids->isEmpty() || $placed->isEmpty()) {
            return [];
        }
        $from = CarbonImmutable::instance($placed->min())->subDays(self::windowDays());
        $to = CarbonImmutable::instance($placed->max());

        $out = [];
        ConversationAdReferral::query()->toBase()
            ->whereIn('customer_id', $ids)
            ->whereBetween('referred_at', [$from, $to])
            ->orderByDesc('referred_at')->orderByDesc('id')
            ->get(['customer_id', 'ad_external_id', 'referred_at'])
            ->each(function (object $r) use (&$out) {
                $out[(int) $r->customer_id][] = ['ad' => (string) $r->ad_external_id, 'at' => CarbonImmutable::parse((string) $r->referred_at, 'UTC')];
            });

        $without = $ids->reject(fn ($id) => isset($out[(int) $id]))->values();
        if ($without->isNotEmpty()) {
            $withHistory = ConversationAdReferral::query()->whereIn('customer_id', $without)->distinct()->pluck('customer_id')->map(fn ($id) => (int) $id)->all();
            $fallback = $without->reject(fn ($id) => in_array((int) $id, $withHistory, true))->values();
            if ($fallback->isNotEmpty()) {
                Conversation::query()
                    ->whereIn('customer_id', $fallback)
                    ->whereNotNull('ad_id')
                    ->whereBetween('ad_attributed_at', [$from, $to])
                    ->orderByDesc('ad_attributed_at')
                    ->get(['customer_id', 'ad_id', 'ad_attributed_at'])
                    ->each(function (Conversation $c) use (&$out) {
                        $out[$c->customer_id][] = ['ad' => (string) $c->ad_id, 'at' => CarbonImmutable::instance($c->ad_attributed_at)];
                    });
            }
        }

        return $out;
    }

    /** crm.ads.inbox_window_days, at least 0. */
    public static function windowDays(): int
    {
        return max(0, (int) config('crm.ads.inbox_window_days', 7));
    }

    /**
     * Spend per ad per day for ads that share a name (tie-break), one query per chunk.
     *
     * @return array<int, array<string, float>> ad id => date => spend
     */
    private function spendFor(Collection $orders): array
    {
        $names = [];
        foreach ($orders as $o) {
            foreach ([$o->utm_content, $o->utm_term] as $v) {
                $v = trim((string) $v);
                $key = mb_strtolower($v);
                if ($v !== '' && ! isset($this->adsByExternal[$v]) && count($this->adsByName[$key] ?? []) > 1) {
                    $names[$key] = true;
                }
            }
        }
        if ($names === []) {
            return [];
        }

        $adIds = collect(array_keys($names))->flatMap(fn ($n) => $this->adsByName[$n])->unique()->values();
        $placed = $orders->pluck('placed_at')->filter();
        $rows = AdDailyMetric::query()
            ->whereIn('ad_id', $adIds)
            ->whereBetween('date', [CarbonImmutable::instance($placed->min())->subDays(7)->toDateString(), CarbonImmutable::instance($placed->max())->toDateString()])
            ->get(['ad_id', 'date', 'spend']);

        $out = [];
        foreach ($rows as $r) {
            $day = $r->date->toDateString();
            $out[(int) $r->ad_id][$day] = ($out[(int) $r->ad_id][$day] ?? 0) + (float) $r->spend;
        }

        return $out;
    }

    /**
     * @param  list<array{ad:string, at:CarbonImmutable}>  $touches
     * @param  array<int, array<string, float>>  $spend
     * @return array{ad_id:?int, ad_campaign_id:?int, ad_attribution:string}|null
     */
    private function resolve(Order $order, array $touches, array $spend): ?array
    {
        foreach ([$order->utm_content, $order->utm_term] as $value) {
            $ad = $this->adFromValue($value, $order, $spend);
            if ($ad !== null) {
                return ['ad_id' => $ad['id'], 'ad_campaign_id' => $ad['campaign'], 'ad_attribution' => 'utm_ad'];
            }
        }

        $campaign = $this->campaignFromValue($order->utm_campaign);
        if ($campaign !== null) {
            return ['ad_id' => null, 'ad_campaign_id' => $campaign, 'ad_attribution' => 'utm_campaign'];
        }

        // Touches are newest first; the latest one before the order, within the window, that resolves to a known ad wins.
        $earliest = CarbonImmutable::instance($order->placed_at)->subDays(self::windowDays());
        foreach ($touches as $t) {
            if ($t['at']->greaterThan($order->placed_at)) {
                continue;
            }
            if ($t['at']->lessThan($earliest)) {
                break;
            }
            $ad = $this->adsByExternal[$t['ad']] ?? null;
            if ($ad !== null) {
                return ['ad_id' => $ad['id'], 'ad_campaign_id' => $ad['campaign'], 'ad_attribution' => 'inbox'];
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, float>>  $spend
     * @return array{id:int, campaign:?int}|null
     */
    private function adFromValue(?string $value, Order $order, array $spend): ?array
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (isset($this->adsByExternal[$value])) {
            return $this->adsByExternal[$value];
        }

        $ids = $this->adsByName[mb_strtolower($value)] ?? [];
        if ($ids === []) {
            return null;
        }
        if (count($ids) === 1) {
            return $this->adsById[$ids[0]];
        }

        $from = CarbonImmutable::instance($order->placed_at)->subDays(7)->toDateString();
        $to = CarbonImmutable::instance($order->placed_at)->toDateString();
        $best = null;
        $bestSpend = -1.0;
        foreach ($ids as $id) { // ascending: a tie keeps the lowest id
            $total = 0.0;
            foreach ($spend[$id] ?? [] as $date => $amount) {
                if ($date >= $from && $date <= $to) {
                    $total += $amount;
                }
            }
            if ($total > $bestSpend) {
                $best = $id;
                $bestSpend = $total;
            }
        }

        return $best !== null ? $this->adsById[$best] : null;
    }

    private function campaignFromValue(?string $value): ?int
    {
        $value = trim((string) $value);

        return $value === '' ? null : ($this->campaignsByExternal[$value] ?? $this->campaignsByName[mb_strtolower($value)] ?? null);
    }
}
