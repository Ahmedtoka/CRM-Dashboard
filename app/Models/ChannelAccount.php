<?php

namespace App\Models;

use App\Enums\Platform;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChannelAccount extends Model
{
    /** @use HasFactory<\Database\Factories\ChannelAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'platform',
        'name',
        'external_id',
        'driver',
        'credentials',
        'status',
        'last_webhook_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'credentials' => 'encrypted:array',
            'last_webhook_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * The Meta Graph API access token to use for this account's calls (send, comment
     * reply/hide, admin test/subscribe). An Instagram account never stores its own
     * token — it always borrows the linked Facebook page's, since that page's token
     * is what Meta actually authorizes Instagram messaging/comment calls with.
     */
    public function graphToken(): ?string
    {
        if ($this->platform === Platform::Instagram) {
            return $this->linkedFacebookAccount()?->credentials['access_token'] ?? null;
        }

        return $this->credentials['access_token'] ?? null;
    }

    /**
     * The Facebook page account this Instagram account borrows its Graph token from,
     * set via `credentials.linked_facebook_account_id` on the settings form.
     */
    public function linkedFacebookAccount(): ?self
    {
        $linkedId = $this->credentials['linked_facebook_account_id'] ?? null;

        if (! $linkedId) {
            return null;
        }

        return static::query()->where('platform', Platform::Facebook)->find($linkedId);
    }
}
