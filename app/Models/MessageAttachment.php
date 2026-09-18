<?php

namespace App\Models;

use App\Enums\AttachmentStatus;
use App\Enums\AttachmentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class MessageAttachment extends Model
{
    /** @use HasFactory<\Database\Factories\MessageAttachmentFactory> */
    use HasFactory;

    protected $fillable = [
        'message_id', 'uploaded_by', 'type', 'disk', 'path', 'mime', 'size_bytes', 'original_name',
        'width', 'height', 'duration_ms', 'remote_url', 'remote_id', 'status', 'error',
    ];

    protected function casts(): array
    {
        return [
            'type' => AttachmentType::class, 'status' => AttachmentStatus::class, 'size_bytes' => 'integer',
            'width' => 'integer', 'height' => 'integer', 'duration_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isStored(): bool
    {
        return $this->status === AttachmentStatus::Stored && $this->path !== null;
    }

    public function absolutePath(): string
    {
        return Storage::disk($this->disk)->path((string) $this->path);
    }
}
