<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\City;
use Illuminate\Http\JsonResponse;

/**
 * Cities for the mobile create-order sheet's shipping-fee picker (spec §5.9).
 * The web app manages cities under /settings/cities; this is a read-only
 * list for the mobile client, which has no such CRUD screen.
 */
class CityController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $cities = City::query()
            ->orderBy('name_ar')
            ->get(['id', 'name_ar', 'name_en', 'shipping_fee'])
            ->map(fn (City $c) => [
                'id' => $c->id,
                'name_ar' => $c->name_ar,
                'name_en' => $c->name_en,
                'shipping_fee' => (float) $c->shipping_fee,
            ]);

        return response()->json(['data' => $cities]);
    }
}
