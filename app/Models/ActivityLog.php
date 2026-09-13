<?php

namespace App\Models;

use App\Enums\ActorType;
use App\Enums\Platform;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    /** @use HasFactory<\Database\Factories\ActivityLogFactory> */
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'actor_type',
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'conversation_id',
        'platform',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'actor_type' => ActorType::class,
            'platform' => Platform::class,
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
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
