<?php

namespace App\Ads\Alerts\Rules;

use App\Ads\Alerts\Finding;
use App\Ads\Alerts\Rule;
use App\Ads\Alerts\RuleContext;
use App\Ads\Alerts\Severity;
use App\Ads\Launch\CaptionPriceParser;
use Carbon\CarbonImmutable;

/**
 * R #9 all.price_mismatch (hourly): the running caption names a price (CaptionPriceParser, Arabic and Latin digits) and
 * none of the named prices is a current variant price of its single linked product. High; the fix is a new ad version.
 */
final class PriceMismatch implements Rule
{
    public const ID = 'all.price_mismatch';

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
            $ad = $ctx->ads[$adId];
            $pids = array_values(array_unique(array_column($rows, 'product_id')));
            $text = trim((string) $ad->body.' '.(string) $ad->headline);
            $product = count($pids) === 1 ? $products->get($pids[0]) : null;
            if ($product === null || $text === '' || $product->variants->isEmpty()) {
                continue;
            }
            $caption = array_values(array_unique(array_map('intval', CaptionPriceParser::prices($text))));
            if ($caption === []) {
                continue;
            }
            $site = $product->variants->map(fn ($v) => (int) round((float) $v->price))->filter(fn (int $p) => $p > 0)->unique()->sort()->values()->all();
            if ($site === [] || array_intersect($caption, $site) !== []) {
                continue;
            }

            $out[] = Finding::forAd(self::ID, $ad, $ctx->family($ad), Severity::HIGH, 'edit_ad', $ctx->dailySpend($adId), 'price_mismatch',
                ['caption_price' => $caption[0], 'site_price' => $site[0], 'site_price_max' => $site[count($site) - 1], 'product' => (string) $product->title],
                ['caption_prices' => $caption, 'site_prices' => $site, 'product_id' => $product->id],
                (int) $product->id);
        }

        return $out;
    }
}
