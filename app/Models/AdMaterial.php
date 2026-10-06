<?php

namespace App\Models;

use Database\Factories\AdMaterialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdMaterial extends Model
{
    /** @use HasFactory<AdMaterialFactory> */
    use HasFactory;

    protected $fillable = ['title', 'product_id', 'types', 'status', 'website_links', 'drive_links', 'ig_links', 'content_notes', 'media_buyer_id', 'created_by_id', 'activated_at', 'done_at', 'need_stop_at', 'stock_override', 'retired_by_id', 'retire_reason'];

    protected function casts(): array
    {
        return ['types' => 'array', 'website_links' => 'array', 'drive_links' => 'array', 'ig_links' => 'array', 'activated_at' => 'datetime', 'done_at' => 'datetime', 'need_stop_at' => 'datetime', 'stock_override' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(AdMaterialCollection::class, 'ad_material_collection', 'ad_material_id', 'ad_material_collection_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(AdMaterialFile::class)->orderBy('sort');
    }

    public function ads(): BelongsToMany
    {
        return $this->belongsToMany(Ad::class, 'ad_material_ads', 'ad_material_id', 'ad_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(MediaBuyer::class, 'media_buyer_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function launches(): HasMany
    {
        return $this->hasMany(AdLaunch::class, 'ad_material_id');
    }
}
