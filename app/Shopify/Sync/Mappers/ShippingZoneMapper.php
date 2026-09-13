<?php

namespace App\Shopify\Sync\Mappers;

use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\ShippingZoneRegion;
use Illuminate\Support\Facades\DB;

final class ShippingZoneMapper
{
    /**
     * Replaces the local zones with the GraphQL `locationGroupZones` nodes in one transaction.
     * Zones and rates are matched by Shopify id so `orders.shipping_rate_id` keeps pointing at
     * the same rows; anything no longer in Shopify is removed. Returns the number of zones.
     */
    public function replaceAll(array $zonesGraphql): int
    {
        $items = Payload::list($zonesGraphql);

        return DB::transaction(function () use ($items) {
            $keptZones = [];

            foreach ($items as $item) {
                $node = $item['zone'] ?? $item;
                $zoneId = Payload::id($node['id'] ?? null);

                if ($zoneId === null) {
                    continue;
                }

                $countries = Payload::list($node['countries'] ?? []);

                $zone = ShippingZone::updateOrCreate(['shopify_zone_id' => $zoneId], [
                    'name' => Payload::string($node['name'] ?? null) ?? $zoneId,
                    'countries' => array_values(array_filter(array_map(fn ($c) => $this->countryCode($c), $countries))),
                ]);

                $this->replaceRegions($zone, $countries);
                $this->syncRates($zone, Payload::list($item['methodDefinitions'] ?? $node['methodDefinitions'] ?? []));
                $keptZones[] = $zone->id;
            }

            $stale = ShippingZone::whereNotIn('id', $keptZones)->pluck('id');
            ShippingRate::whereIn('shipping_zone_id', $stale)->delete();
            ShippingZoneRegion::whereIn('shipping_zone_id', $stale)->delete();
            ShippingZone::whereIn('id', $stale)->delete();

            return count($keptZones);
        });
    }

    private function replaceRegions(ShippingZone $zone, array $countries): void
    {
        $zone->regions()->delete();

        foreach ($countries as $country) {
            $code = $this->countryCode($country);

            if ($code === null) {
                continue; // "Rest of world" has no country code to store.
            }

            $provinces = $country['provinces'] ?? [];

            if ($provinces === []) {
                $zone->regions()->create(['country_code' => $code, 'province_code' => null, 'province_name' => null]);

                continue;
            }

            foreach ($provinces as $province) {
                $zone->regions()->create([
                    'country_code' => $code,
                    'province_code' => Payload::string($province['code'] ?? null),
                    'province_name' => Payload::string($province['name'] ?? null),
                ]);
            }
        }
    }

    private function syncRates(ShippingZone $zone, array $methods): void
    {
        $kept = [];

        foreach ($methods as $method) {
            $rateId = Payload::id($method['id'] ?? null);
            $price = Payload::moneyOrNull($method['rateProvider']['price'] ?? null);

            // Inactive methods and carrier-calculated rates (no fixed price) are not offered.
            if ($rateId === null || ($method['active'] ?? true) === false || $price === null) {
                continue;
            }

            $min = null;
            $max = null;

            foreach ($method['methodConditions'] ?? [] as $condition) {
                if (($condition['field'] ?? null) !== 'TOTAL_PRICE') {
                    continue;
                }

                $amount = Payload::moneyOrNull($condition['conditionCriteria'] ?? null);
                match ($condition['operator'] ?? null) {
                    'GREATER_THAN_OR_EQUAL_TO' => $min = $amount,
                    'LESS_THAN_OR_EQUAL_TO' => $max = $amount,
                    default => null,
                };
            }

            ShippingRate::updateOrCreate(['shipping_zone_id' => $zone->id, 'shopify_rate_id' => $rateId], [
                'title' => Payload::string($method['name'] ?? null),
                'price' => $price,
                'min_order_subtotal' => $min,
                'max_order_subtotal' => $max,
            ]);
            $kept[] = $rateId;
        }

        ShippingRate::where('shipping_zone_id', $zone->id)
            ->where(fn ($q) => $q->whereNull('shopify_rate_id')->orWhereNotIn('shopify_rate_id', $kept))
            ->delete();
    }

    private function countryCode(mixed $country): ?string
    {
        $code = is_array($country) ? ($country['code']['countryCode'] ?? (is_string($country['code'] ?? null) ? $country['code'] : null)) : null;

        return is_string($code) && strlen($code) === 2 ? strtoupper($code) : null;
    }
}
