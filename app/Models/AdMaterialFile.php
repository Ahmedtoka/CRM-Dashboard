<?php

namespace App\Models;

use Database\Factories\AdMaterialFileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdMaterialFile extends Model
{
    /** @use HasFactory<AdMaterialFileFactory> */
    use HasFactory;

    protected $fillable = ['ad_material_id', 'disk', 'path', 'thumb_path', 'mime', 'size', 'width', 'height', 'duration', 'original_name', 'sort', 'platform_media'];

    protected function casts(): array
    {
        return ['platform_media' => 'array'];
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(AdMaterial::class, 'ad_material_id');
    }
}
