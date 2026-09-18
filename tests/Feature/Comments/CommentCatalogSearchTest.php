<?php

use App\Bot\CatalogSearch;
use App\Models\{Product, ProductVariant};

it('finds catalog lines for comment words starting with an Arabic article', function () {
    $p = Product::factory()->create(['title' => 'الفستان الأسود']);
    ProductVariant::factory()->for($p)->create(['sku' => 'DR-7', 'title' => 'Default', 'price' => 990, 'inventory_quantity' => 4]);

    expect(app(CatalogSearch::class)->linesFor('الفستان؟'))
        ->toBe(['الفستان الأسود | SKU DR-7 | 990 جنيه | متاح 4']);
});
