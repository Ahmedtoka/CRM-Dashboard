<?php

namespace App\Models;

use App\Enums\Platform;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory;

    protected $fillable = [
        'channel_account_id',
        'platform',
        'external_id',
        'caption',
        'permalink',
        'thumbnail_url',
        'is_ad',
        // The ad behind the post (2026-09-25): ClassifyAdPost / the Instagram comment webhook.
        'ad_id',
        'ad_title',
        'ad_name',
        'ad_adset_name',
        'ad_campaign_name',
        'ad_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'is_ad' => 'boolean',
            'ad_checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ChannelAccount, $this>
     */
    public function channelAccount(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class);
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }
}
