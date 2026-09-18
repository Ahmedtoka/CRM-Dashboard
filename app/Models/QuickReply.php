<?php

namespace App\Models;

use App\Enums\QuickReplyScope;
use Database\Factories\QuickReplyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuickReply extends Model
{
    /** @use HasFactory<QuickReplyFactory> */
    use HasFactory;

    protected $fillable = [
        'shortcut',
        'title',
        'body',
        'platforms',
        'created_by',
        'scope',
        'user_id',
        'category_id',
        'use_count',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'platforms' => 'array',
            'scope' => QuickReplyScope::class,
            'use_count' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<QuickReplyCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(QuickReplyCategory::class, 'category_id');
    }

    /**
     * @return HasMany<QuickReplyAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(QuickReplyAttachment::class)->orderBy('sort');
    }

    /**
     * @return HasMany<QuickReplyUsage, $this>
     */
    public function usages(): HasMany
    {
        return $this->hasMany(QuickReplyUsage::class);
    }

    /**
     * Shared replies plus this user's own personal replies — the set a user
     * may use or, when combined with the caller's own scoping, manage.
     */
    public function scopeUsableBy(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('scope', QuickReplyScope::Shared->value)
            ->orWhere(fn (Builder $p) => $p->where('scope', QuickReplyScope::Personal->value)->where('user_id', $user->id)));
    }
}
