<?php

namespace App\Http\Controllers\Concerns;

use App\Commerce\ShippingQuote;
use App\Models\ShippingZoneRegion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Province list + shipping quote for the order drawer (spec §5.1, plan Task 9).
 * Shared by the web order drawer (/shipping/...) and the mobile app (/api/v1/shipping/...).
 */
trait ShippingEndpoints
{
    /**
     * Distinct EG governorates seen in the synced shipping zones, Arabic name from
     * config('crm.eg_provinces') when known, else the Shopify-supplied province name.
     */
    public function provinces(): JsonResponse
    {
        $names = config('crm.eg_provinces', []);

        $data = ShippingZoneRegion::query()
            ->where('country_code', 'EG')
            ->whereNotNull('province_code')
            ->select('province_code', 'province_name')
            ->distinct()
            ->orderBy('province_code')
            ->get()
            ->unique('province_code')
            ->map(fn (ShippingZoneRegion $r) => [
                'code' => $r->province_code,
                'name' => $names[$r->province_code] ?? $r->province_name ?? $r->province_code,
            ])
            ->values();

        return response()->json(['data' => $data]);
    }

    public function quote(Request $request, ShippingQuote $quote): JsonResponse
    {
        $data = $request->validate([
            'province_code' => ['nullable', 'string', 'max:10'],
            'subtotal' => ['required', 'numeric', 'min:0'],
        ]);

        $options = $quote->quote($data['province_code'] ?? null, (string) $data['subtotal']);

        return response()->json(['data' => array_map(fn ($o) => [
            'rate_id' => $o->rateId,
            'title' => $o->title,
            'price' => $o->price,
        ], $options)]);
    }
}
