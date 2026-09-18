<?php

namespace App\Models;

use App\Enums\Platform;
use Database\Factories\ChannelAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChannelAccount extends Model
{
    /** @use HasFactory<ChannelAccountFactory> */
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
        'profile',
        'connected_at',
        'health',
        'health_status',
        'health_checked_at',
    ];

    /** Tokens and other secrets never leave the server (Inertia props, JSON). */
    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'credentials' => 'encrypted:array',
            'last_webhook_at' => 'datetime',
            'profile' => 'array',
            'connected_at' => 'datetime',
            'health' => 'array',
            'health_checked_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function isLive(): bool
    {
        return $this->driver === 'live';
    }

    /**
     * The WhatsApp Business Account id of a WhatsApp number (kept with the non-secret
     * profile data; older manual setups may still have it in the credentials).
     */
    public function wabaId(): ?string
    {
        $id = $this->profile['waba_id'] ?? $this->credentials['waba_id'] ?? null;

        return filled($id) ? (string) $id : null;
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
