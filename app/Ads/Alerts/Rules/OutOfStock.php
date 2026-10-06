<?php

namespace App\Ads\Alerts\Rules;

use App\Ads\Alerts\Family;
use App\Ads\Alerts\Finding;
use App\Ads\Alerts\Rule;
use App\Ads\Alerts\RuleContext;
use App\Ads\Alerts\Severity;
use Carbon\CarbonImmutable;

/**
 * R #1 all.out_of_stock (hourly): a running ad whose linked product is out per MaterialService::stockSql() (the manual
 * override wins). Sales → critical Stop; Messages ads and multi-product creatives → high "check stock" (R-10: chat staff
 * can offer another size or colour). A fact, not a judgement: no learning, sample or freshness gate. Never auto-stops (D6).
 */
final class OutOfStock implements Rule
{
    public const ID = 'all.out_of_stock';

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
        return $now->addHour();
    }

    public function evaluate(RuleContext $ctx): array
    {
        $links = $ctx->data->productLinks($ctx->ads->keys()->all());
        if ($links === []) {
            return [];
        }
        $productIds = array_values(array_unique(array_merge(...array_map(fn (array $rows) => array_column($rows, 'product_id'), array_values($links)))));
        $products = $ctx->data->products($productIds);

        $out = [];
        foreach ($links as $adId => $rows) {
            $outRows = array_values(array_filter($rows, fn (array $r) => $r['stock'] === 'out'));
            if ($outRows === []) {
                continue;
            }
            $ad = $ctx->ads[$adId];
            $productId = $outRows[0]['product_id'];
            $product = $products->get($productId);
            $multi = count(array_unique(array_column($rows, 'product_id'))) > 1;
            $family = $ctx->family($ad);
            $soft = $family === Family::MESSAGES || $multi;

            $out[] = Finding::forAd(self::ID, $ad, $family,
                $soft ? Severity::HIGH : Severity::CRITICAL, $soft ? 'check_stock' : 'stop',
                $ctx->dailySpend($adId), $soft ? 'out_of_stock_msg' : 'out_of_stock',
                ['product' => (string) ($product?->title ?? '')],
                [
                    'product_id' => $productId, 'inventory' => (int) ($product?->variants->sum('inventory_quantity') ?? 0),
                    'multi_product' => $multi, 'out_since' => collect($outRows)->pluck('need_stop_at')->filter()->min(),
                ],
                $productId);
        }

        return $out;
    }
}
