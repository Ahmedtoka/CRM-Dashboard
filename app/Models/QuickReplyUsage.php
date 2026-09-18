<?php

namespace App\Models;

use App\Enums\Platform;
use Database\Factories\QuickReplyUsageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuickReplyUsage extends Model
{
    /** @use HasFactory<QuickReplyUsageFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'quick_reply_id',
        'user_id',
        'conversation_id',
        'platform',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
            'platform' => Platform::class,
        ];
    }

    /**
     * @return BelongsTo<QuickReply, $this>
     */
    public function reply(): BelongsTo
    {
        return $this->belongsTo(QuickReply::class, 'quick_reply_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
