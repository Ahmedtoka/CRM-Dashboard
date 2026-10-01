<?php

namespace App\Models;

use Database\Factories\AdMaterialCollectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AdMaterialCollection extends Model
{
    /** @use HasFactory<AdMaterialCollectionFactory> */
    use HasFactory;

    protected $fillable = ['name', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort' => 'integer'];
    }

    public function materials(): BelongsToMany
    {
        return $this->belongsToMany(AdMaterial::class, 'ad_material_collection', 'ad_material_collection_id', 'ad_material_id');
    }
}
