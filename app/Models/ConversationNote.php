<?php

namespace App\Models;

use Database\Factories\ConversationNoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationNote extends Model
{
    /** @use HasFactory<ConversationNoteFactory> */
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'user_id',
        'body',
        'mentions',
    ];

    protected $casts = [
        'mentions' => 'array',
    ];

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
