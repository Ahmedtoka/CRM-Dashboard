<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One paused ad the CRM publishes (or published) for a material file and caption (see PublishService / PublishAd). */
class AdPublication extends Model
{
    public const QUEUED = 'queued';

    public const UPLOADING = 'uploading';

    public const PROCESSING = 'processing';

    public const CREATING = 'creating';

    public const DONE = 'done';

    public const ERROR = 'error';

    protected $fillable = [
        'ad_material_id', 'ad_material_file_id', 'ad_account_id', 'platform', 'campaign_external_id', 'campaign_name', 'adset_external_id', 'adset_name',
        'identity', 'caption_index', 'headline', 'primary_text', 'cta', 'ad_name', 'link', 'url_tags', 'status', 'external_ad_id', 'error', 'attempts',
        'linked_at', 'ad_requested_at', 'created_by_id', 'idempotency_key', 'open_key', 'allow_duplicate',
    ];

    protected function casts(): array
    {
        return ['identity' => 'array', 'caption_index' => 'integer', 'attempts' => 'integer', 'linked_at' => 'datetime', 'ad_requested_at' => 'datetime', 'allow_duplicate' => 'boolean'];
    }

    protected static function booted(): void
    {
        // A failed publication must not block publishing the same thing again (W2): the in-flight key is released on error.
        static::saving(function (self $p) {
            if ($p->status === self::ERROR && $p->open_key !== null) {
                $p->open_key = null;
            }
        });
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(AdMaterial::class, 'ad_material_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(AdMaterialFile::class, 'ad_material_file_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AdAccount::class, 'ad_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::DONE, self::ERROR], true);
    }
}
