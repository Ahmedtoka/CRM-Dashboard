<?php

namespace App\Ads\Alerts\Rules;

use App\Ads\Alerts\Finding;
use App\Ads\Alerts\Rule;
use App\Ads\Alerts\RuleContext;
use App\Ads\Alerts\Severity;
use Carbon\CarbonImmutable;

/** R #11 all.product_unavailable (hourly): the linked product is not active on Shopify (draft, archived) or was deleted. */
final class ProductUnavailable implements Rule
{
    public const ID = 'all.product_unavailable';

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

        $out = [];
        foreach ($links as $adId => $rows) {
            foreach (array_unique(array_column($rows, 'product_id')) as $pid) {
                $product = $products->get($pid);
                if ($product === null) {
                    continue;
                }
                $status = $product->trashed() ? 'deleted' : strtolower((string) $product->status);
                if ($status === 'active') {
                    continue;
                }
                $ad = $ctx->ads[$adId];
                $out[] = Finding::forAd(self::ID, $ad, $ctx->family($ad), Severity::CRITICAL, 'stop', $ctx->dailySpend($adId), 'product_unavailable',
                    ['product' => (string) $product->title, 'status' => $status], ['product_id' => $product->id, 'status' => $status], (int) $product->id);
                break; // one finding per ad
            }
        }

        return $out;
    }
}
