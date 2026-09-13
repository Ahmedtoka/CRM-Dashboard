<?php

namespace Database\Factories;

use App\Models\ShippingZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ShippingZoneRegion>
 */
class ShippingZoneRegionFactory extends Factory
{
    public function definition(): array
    {
        $province = fake()->randomElement([
            ['code' => 'C', 'name' => 'Cairo'],
            ['code' => 'GZ', 'name' => 'Giza'],
            ['code' => 'ALX', 'name' => 'Alexandria'],
        ]);

        return [
            'shipping_zone_id' => ShippingZone::factory(),
            'country_code' => 'EG',
            'province_code' => $province['code'],
            'province_name' => $province['name'],
        ];
    }
}
