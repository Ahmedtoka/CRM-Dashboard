<?php

namespace App\Models;

use App\Enums\AttachmentType;
use Database\Factories\QuickReplyAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuickReplyAttachment extends Model
{
    /** @use HasFactory<QuickReplyAttachmentFactory> */
    use HasFactory;

    protected $fillable = [
        'quick_reply_id',
        'disk',
        'path',
        'type',
        'mime',
        'size_bytes',
        'original_name',
        'width',
        'height',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'type' => AttachmentType::class,
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'sort' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<QuickReply, $this>
     */
    public function reply(): BelongsTo
    {
        return $this->belongsTo(QuickReply::class, 'quick_reply_id');
    }
}
