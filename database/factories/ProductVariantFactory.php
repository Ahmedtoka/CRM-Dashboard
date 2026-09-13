<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'shopify_id' => (string) fake()->unique()->numberBetween(1000000, 9999999),
            'sku' => strtoupper(fake()->bothify('SKU-####')),
            'title' => 'Default',
            'price' => fake()->randomFloat(2, 50, 2000),
            'inventory_quantity' => fake()->numberBetween(0, 100),
        ];
    }
}
