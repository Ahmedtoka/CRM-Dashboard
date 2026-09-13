<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    /** @use HasFactory<\Database\Factories\ProductVariantFactory> */
    use HasFactory;

    protected $fillable = [
        'product_id',
        'shopify_id',
        'sku',
        'title',
        'price',
        'inventory_quantity',
        'image_url',
        'compare_at_price',
        'barcode',
        'inventory_item_id',
        'inventory_policy',
        'requires_shipping',
        'shopify_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'inventory_quantity' => 'integer',
            'compare_at_price' => 'decimal:2',
            'requires_shipping' => 'boolean',
            'shopify_updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
