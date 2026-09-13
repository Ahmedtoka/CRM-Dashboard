<?php

namespace App\Models;

use App\Enums\ActorType;
use App\Enums\CommentIntent;
use App\Enums\CommentStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Comment extends Model
{
    /** @use HasFactory<\Database\Factories\CommentFactory> */
    use HasFactory;

    protected $fillable = [
        'post_id',
        'customer_id',
        'external_id',
        'parent_external_id',
        'body',
        'status',
        'intent',
        'public_reply',
        'public_replied_at',
        'replied_by_type',
        'replied_by_id',
        'private_reply_sent_at',
        'conversation_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => CommentStatus::class,
            'intent' => CommentIntent::class,
            'public_replied_at' => 'datetime',
            'replied_by_type' => ActorType::class,
            'private_reply_sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * Comments have no platform column of their own; derive it from the
     * post so ActivityLogger::log() (which reads
     * `$subject->getAttribute('platform')`) records it correctly.
     */
    protected function platform(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->post?->platform,
        );
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The staff member who posted the public reply (null for bot replies).
     *
     * @return BelongsTo<User, $this>
     */
    public function repliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replied_by_id');
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
