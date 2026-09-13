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
