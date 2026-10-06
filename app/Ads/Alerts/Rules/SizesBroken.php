<?php

namespace App\Ads\Alerts\Rules;

use App\Ads\Alerts\Family;
use App\Ads\Alerts\Finding;
use App\Ads\Alerts\Rule;
use App\Ads\Alerts\RuleContext;
use App\Ads\Alerts\Severity;
use Carbon\CarbonImmutable;

/**
 * R #10 all.sizes_broken (daily): the product is "in stock" but under half of its sizes are, or its two best-selling
 * sizes of the last 60 days are both out. Sales high, others medium; action "check stock".
 */
final class SizesBroken implements Rule
{
    public const ID = 'all.sizes_broken';

    public const MIN_SHARE = 0.5;

    public const TOP_SIZES = 2;

    public const SALES_DAYS = 60;

    public function id(): string
    {
        return self::ID;
    }

    public function schedule(): string
    {
        return self::DAILY;
    }

    public function isPerformance(): bool
    {
        return false;
    }

    public function cooldownUntil(CarbonImmutable $now): CarbonImmutable
    {
        return $now->addHours(24);
    }

    public function evaluate(RuleContext $ctx): array
    {
        $links = $ctx->data->productLinks($ctx->ads->keys()->all());
        if ($links === []) {
            return [];
        }
        $productIds = array_values(array_unique(array_merge(...array_map(fn (array $rows) => array_column($rows, 'product_id'), array_values($links)))));
        $products = $ctx->data->products($productIds);
        $top = $ctx->data->topVariants($productIds, $ctx->day(-self::SALES_DAYS), $ctx->day(-1), self::TOP_SIZES);

        $out = [];
        foreach ($links as $adId => $rows) {
            $pids = array_values(array_unique(array_column($rows, 'product_id')));
            $product = count($pids) === 1 ? $products->get($pids[0]) : null;
            if ($product === null || $product->variants->count() < 2 || (int) $product->variants->sum('inventory_quantity') <= 0) {
                continue;
            }
            $variants = $product->variants->sortBy('id')->values();
            $inStock = $variants->filter(fn ($v) => (int) $v->inventory_quantity > 0);
            $share = $inStock->count() / $variants->count();
            $best = $top[$product->id] ?? [];
            $topOut = count($best) >= self::TOP_SIZES && $variants->whereIn('id', $best)->every(fn ($v) => (int) $v->inventory_quantity <= 0);
            if ($share >= self::MIN_SHARE && ! $topOut) {
                continue;
            }

            $ad = $ctx->ads[$adId];
            $family = $ctx->family($ad);
            $out[] = Finding::forAd(self::ID, $ad, $family, $family === Family::SALES ? Severity::HIGH : Severity::MEDIUM, 'check_stock',
                round($ctx->dailySpend($adId) * (1 - $share), 2), 'sizes_broken',
                ['product' => (string) $product->title, 'sizes' => $variants->filter(fn ($v) => (int) $v->inventory_quantity <= 0)->pluck('title')->take(4)->implode('، '), 'share' => (int) round($share * 100)],
                ['product_id' => $product->id, 'top_sellers' => $best, 'top_sellers_out' => $topOut,
                    'variants' => $variants->map(fn ($v) => ['title' => (string) $v->title, 'inventory' => (int) $v->inventory_quantity])->all()],
                (int) $product->id);
        }

        return $out;
    }
}
