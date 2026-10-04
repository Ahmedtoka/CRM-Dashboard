<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One of the three captions (emotional, offer, quality) written for a material's video; editable by hand. */
class AdMaterialCaption extends Model
{
    protected $fillable = [
        'ad_material_id', 'ad_material_file_id', 'position', 'angle', 'headline', 'primary_text', 'cta', 'model',
        'input_tokens', 'output_tokens', 'edited_by_id',
    ];

    public function material(): BelongsTo
    {
        return $this->belongsTo(AdMaterial::class, 'ad_material_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(AdMaterialFile::class, 'ad_material_file_id');
    }
}
