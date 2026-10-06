<?php

namespace App\Ads\Alerts\Rules;

use App\Ads\Alerts\AlertData;
use App\Ads\Alerts\Family;
use App\Ads\Alerts\Finding;
use App\Ads\Alerts\Rule;
use App\Ads\Alerts\RuleContext;
use App\Ads\Alerts\Severity;
use App\Ads\Control\AdWriteService;
use App\Ads\Reports\AdsFilter;
use App\Models\Ad;
use App\Models\AdsAlert;
use Carbon\CarbonImmutable;

/**
 * R #18 rec.reactivate_restocked (hourly): an ad stopped from an out_of_stock alert is still paused and its product is
 * back above the low-stock units (R-10). Suggests Run (guarded, re-auth per Phase B).
 */
final class ReactivateRestocked implements Rule
{
    public const ID = 'rec.reactivate_restocked';

    public const LOOKBACK_DAYS = 30;

    public function id(): string
    {
        return self::ID;
    }

    public function schedule(): string
    {
        return self::HOURLY;
    }

    public function isPerformance(): bool
    {
        return false;
    }

    public function cooldownUntil(CarbonImmutable $now): CarbonImmutable
    {
        return $now;
    }

    public function evaluate(RuleContext $ctx): array
    {
        $acted = AdsAlert::query()->where('ad_account_id', $ctx->account->id)->where('rule_id', OutOfStock::ID)
            ->where('state', AdsAlert::ACTED)->whereNotNull('ad_id')->whereNotNull('product_id')
            ->where('closed_at', '>=', $ctx->now->subDays(self::LOOKBACK_DAYS)->utc())
            ->orderByDesc('closed_at')->get()->unique('ad_id');
        if ($acted->isEmpty()) {
            return [];
        }
        $paused = Ad::query()->with('campaign:id,objective')->whereIn('id', $acted->pluck('ad_id'))
            ->whereNotIn('status', AdWriteService::ACTIVE_STATUSES)->get()->keyBy('id');
        $links = $ctx->data->productLinks($paused->keys()->all());
        $products = $ctx->data->products($acted->pluck('product_id')->unique()->values()->all());
        $low = $ctx->settings->lowStockUnits();

        $out = [];
        foreach ($acted as $alert) {
            $ad = $paused->get($alert->ad_id);
            $product = $products->get($alert->product_id);
            if ($ad === null || $product === null) {
                continue;
            }
            $link = collect($links[$ad->id] ?? [])->firstWhere('product_id', (int) $product->id);
            $units = (int) $product->variants->sum('inventory_quantity');
            if ($link === null || $link['stock'] !== 'in' || $units <= $low) {
                continue;
            }
            $stopDay = CarbonImmutable::parse($alert->closed_at)->setTimezone(AdsFilter::TIMEZONE)->startOfDay();
            $before = $ctx->data->sums([$ad->id], $stopDay->subDays(3)->toDateString(), $stopDay->subDay()->toDateString())[$ad->id] ?? AlertData::emptySums();

            $out[] = Finding::forAd(self::ID, $ad, Family::of($ad->campaign?->objective), Severity::HIGH, 'run',
                round($before['spend'] / 3, 2), 'reactivate_restocked',
                ['product' => (string) $product->title, 'units' => $units],
                ['stopped_alert_id' => $alert->id, 'low_stock_units' => $low, 'product_id' => $product->id],
                (int) $product->id, 'recommendation');
        }

        return $out;
    }
}
