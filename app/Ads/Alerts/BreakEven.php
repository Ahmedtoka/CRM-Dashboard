<?php

namespace App\Ads\Alerts;

use App\Ads\AdsSettings;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\AdsQuery;
use App\Enums\ShipmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Break-even ROAS per account (D12, R 1.2). Inputs come from RuleSettings (account, else global); refusal rate and AOV are
 * measured over the last 60 days from orders.shipment_status / delivered_at and net revenue (account orders first, then
 * the whole store, then an assumed 20 % refusal). F = 2.5 with is_default=true until a margin is entered.
 */
final class BreakEven
{
    public const DEFAULT_FLOOR = 2.5;

    public const MAX_FLOOR = 10.0;

    public const WINDOW_DAYS = 60;

    public const MIN_SAMPLE = 20;

    public const ASSUMED_REFUSAL = 0.2;

    /**
     * Shipment statuses that mean the COD order came back (refused or returned). FailedAttempt is NOT here: the courier
     * retries and the order is still on the way (OrderLookup shows it as such); it counts once it ends as Returned or
     * Delivered.
     */
    public const REFUSED = [ShipmentStatus::Returned->value];

    /** @var array<int, array<string, mixed>> */
    private array $memo = [];

    public function __construct(private readonly RuleSettings $settings, private readonly AdsSettings $ads) {}

    /** @return array{floor: float, is_default: bool} */
    public function forAccount(int $accountId): array
    {
        $e = $this->explain($accountId);

        return ['floor' => $e['floor'], 'is_default' => $e['is_default']];
    }

    /** @return array{floor: float, is_default: bool, unprofitable: bool, margin_pct: ?float, shipping_subsidy: float, return_cost: float, aov: ?float, aov_source: string, refusal_rate: float, refusal_source: string, max_cpa: ?float, tax_rate: float} */
    public function explain(int $accountId): array
    {
        if (isset($this->memo[$accountId])) {
            return $this->memo[$accountId];
        }

        $in = $this->settings->inputsFor($accountId)['values'];
        $m = $this->measured($accountId);
        $tax = $this->ads->taxRate();
        $out = [
            'floor' => self::DEFAULT_FLOOR, 'is_default' => true, 'unprofitable' => false,
            'margin_pct' => $in['margin_pct'], 'shipping_subsidy' => (float) ($in['shipping_subsidy'] ?? 0), 'return_cost' => (float) ($in['return_cost'] ?? 0),
            'aov' => $m['aov'], 'aov_source' => $m['aov_source'], 'refusal_rate' => $m['refusal_rate'], 'refusal_source' => $m['refusal_source'],
            'max_cpa' => null, 'tax_rate' => $tax,
        ];

        if ($in['margin_pct'] !== null && $in['margin_pct'] > 0 && $m['aov'] !== null && $m['aov'] > 0) {
            $maxCpa = self::maxCpa($m['aov'], $in['margin_pct'], $out['shipping_subsidy'], $out['return_cost'], $m['refusal_rate'], $tax);
            $out['is_default'] = false;
            $out['max_cpa'] = $maxCpa;
            $out['unprofitable'] = $maxCpa === null;
            $out['floor'] = $maxCpa === null ? self::MAX_FLOOR : min(self::MAX_FLOOR, round($m['aov'] / $maxCpa, 2));
        }

        return $this->memo[$accountId] = $out;
    }

    /** Pre-tax highest cost per placed order that still breaks even; null when an order loses money before any ad. */
    public static function maxCpa(float $aov, float $marginPct, float $shipping, float $returnCost, float $refusal, float $tax): ?float
    {
        $contribution = (1 - $refusal) * ($aov * $marginPct / 100 - $shipping) - $refusal * $returnCost;

        return $contribution <= 0 ? null : round($contribution / (1 + $tax), 2);
    }

    /** @return array{aov: ?float, aov_source: string, refusal_rate: float, refusal_source: string} */
    public function measured(int $accountId): array
    {
        $since = CarbonImmutable::now(AdsFilter::TIMEZONE)->startOfDay()->subDays(self::WINDOW_DAYS)->utc();
        $account = fn (): Builder => $this->orders($since)->where(fn ($w) => $w
            ->whereIn('o.ad_id', DB::table('ads')->where('ad_account_id', $accountId)->select('id'))
            ->orWhereIn('o.ad_campaign_id', DB::table('ad_campaigns')->where('ad_account_id', $accountId)->select('id')));
        $store = fn (): Builder => $this->orders($since);

        [$aov, $aovSource] = $this->aov($account(), $store());
        [$rate, $rateSource] = $this->refusal($account(), $store());

        return ['aov' => $aov, 'aov_source' => $aovSource, 'refusal_rate' => $rate, 'refusal_source' => $rateSource];
    }

    private function orders(CarbonImmutable $since): Builder
    {
        return DB::table('orders as o')->whereNotIn('o.status', AdsQuery::NOT_REAL_STATUSES)->where('o.placed_at', '>=', $since);
    }

    /** @return array{0: ?float, 1: string} */
    private function aov(Builder $account, Builder $store): array
    {
        $sql = 'COUNT(*) as n, AVG('.AdsQuery::netRevenueSql().') as aov';
        $a = $account->selectRaw($sql)->first();
        if ((int) ($a->n ?? 0) >= self::MIN_SAMPLE) {
            return [round((float) $a->aov, 2), 'account'];
        }
        $s = $store->selectRaw($sql)->first();

        return (int) ($s->n ?? 0) > 0 ? [round((float) $s->aov, 2), 'store'] : [null, 'none'];
    }

    /** @return array{0: float, 1: string} */
    private function refusal(Builder $account, Builder $store): array
    {
        $refused = "'".implode("','", self::REFUSED)."'";
        $sql = "SUM(CASE WHEN o.shipment_status IN ({$refused}) THEN 1 ELSE 0 END) as refused, "
            ."SUM(CASE WHEN o.shipment_status IN ({$refused}) OR o.shipment_status = 'delivered' OR o.delivered_at IS NOT NULL THEN 1 ELSE 0 END) as terminal";
        foreach (['account' => $account, 'store' => $store] as $source => $q) {
            $r = $q->selectRaw($sql)->first();
            $terminal = (int) ($r->terminal ?? 0);
            if ($terminal >= self::MIN_SAMPLE) {
                return [round((int) $r->refused / $terminal, 4), $source];
            }
        }

        return [self::ASSUMED_REFUSAL, 'assumed'];
    }
}
