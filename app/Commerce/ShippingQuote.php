<?php

namespace App\Commerce;

use App\Commerce\Data\ShippingOption;
use App\Models\ShippingRate;
use App\Models\ShippingZoneRegion;
use App\Shopify\Connection\IntegrationRepository;
use App\Shopify\Connection\ShopifyIntegration;

/**
 * Shipping choices for the order drawer (spec §5.1): the rates of the zones
 * containing EG + the governorate, filtered by the subtotal bounds; none →
 * the integration's `default_shipping_fee` titled "شحن".
 */
final class ShippingQuote
{
    public const DEFAULT_TITLE = 'شحن';

    public function __construct(private readonly IntegrationRepository $integrations) {}

    /** @return list<ShippingOption> */
    public function quote(?string $provinceCode, string $subtotal): array
    {
        $options = $provinceCode === null || $provinceCode === '' ? [] : $this->zoneRates($provinceCode, (float) $subtotal);

        return $options !== [] ? $options : [$this->defaultOption()];
    }

    /**
     * The first Shopify zone rate for a governorate at subtotal 0 (what the bot
     * quotes), or null when Shopify has no rate for it. Unlike quote(), never
     * falls back to the integration's default fee.
     */
    public function shopifyRate(string $provinceCode): ?ShippingOption
    {
        return $this->zoneRates($provinceCode, 0.0)[0] ?? null;
    }

    /**
     * The one domestic rate when every Egyptian governorate Shopify ships to has
     * the same first rate (a flat fee), else null (the fee depends on the
     * governorate, or nothing is synced). Two queries, whatever the zone count.
     */
    public function domesticFlatRate(): ?ShippingOption
    {
        $zonesByProvince = ShippingZoneRegion::query()
            ->where('country_code', 'EG')
            ->whereNotNull('province_code')
            ->get(['shipping_zone_id', 'province_code'])
            ->groupBy('province_code')
            ->map(fn ($regions) => $regions->pluck('shipping_zone_id')->all());

        if ($zonesByProvince->isEmpty()) {
            return null;
        }

        $rates = ShippingRate::query()
            ->whereIn('shipping_zone_id', $zonesByProvince->flatten()->unique()->all())
            ->orderBy('id')
            ->get()
            ->filter(fn (ShippingRate $r) => $r->min_order_subtotal === null || (float) $r->min_order_subtotal <= 0.0)
            ->filter(fn (ShippingRate $r) => $r->max_order_subtotal === null || (float) $r->max_order_subtotal >= 0.0);

        $firsts = $zonesByProvince->map(fn (array $zoneIds) => $rates->first(fn (ShippingRate $r) => in_array($r->shipping_zone_id, $zoneIds, false)));

        if ($firsts->contains(null) || $firsts->map(fn (ShippingRate $r) => self::money($r->price))->unique()->count() !== 1) {
            return null;
        }

        $rate = $firsts->first();

        return new ShippingOption($rate->id, $rate->title, self::money($rate->price));
    }

    public function defaultOption(): ShippingOption
    {
        $settings = ($this->integrations->current() ?? new ShopifyIntegration)->settingsWithDefaults();

        return new ShippingOption(null, self::DEFAULT_TITLE, self::money($settings['default_shipping_fee']));
    }

    /** @return list<ShippingOption> */
    private function zoneRates(string $provinceCode, float $subtotal): array
    {
        $zoneIds = ShippingZoneRegion::query()
            ->where('country_code', 'EG')
            ->where('province_code', $provinceCode)
            ->pluck('shipping_zone_id');

        if ($zoneIds->isEmpty()) {
            return [];
        }

        return ShippingRate::query()
            ->whereIn('shipping_zone_id', $zoneIds)
            ->orderBy('id')
            ->get()
            ->filter(fn (ShippingRate $r) => ($r->min_order_subtotal === null || $subtotal >= (float) $r->min_order_subtotal)
                && ($r->max_order_subtotal === null || $subtotal <= (float) $r->max_order_subtotal))
            ->map(fn (ShippingRate $r) => new ShippingOption($r->id, $r->title, self::money($r->price)))
            ->values()
            ->all();
    }

    private static function money(string|float|int|null $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
