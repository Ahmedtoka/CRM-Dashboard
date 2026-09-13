<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuickReply extends Model
{
    /** @use HasFactory<\Database\Factories\QuickReplyFactory> */
    use HasFactory;

    protected $fillable = [
        'shortcut',
        'title',
        'body',
        'platforms',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'platforms' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
