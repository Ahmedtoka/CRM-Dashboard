<?php

namespace App\Models;

use App\Enums\AttachmentStatus;
use App\Enums\AttachmentType;
use App\Media\Jobs\MakeThumbnail;
use App\Media\Thumbnailer;
use Database\Factories\MessageAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class MessageAttachment extends Model
{
    /** @use HasFactory<MessageAttachmentFactory> */
    use HasFactory;

    protected $fillable = [
        'message_id', 'uploaded_by', 'type', 'disk', 'path', 'mime', 'size_bytes', 'original_name',
        'width', 'height', 'duration_ms', 'thumb_path', 'remote_url', 'remote_id', 'status', 'error',
    ];

    protected static function booted(): void
    {
        // A stored image/sticker gets its inbox thumbnail (the job runs after the commit).
        // Created already stored, or newly stored by an update of status/path: never on other saves.
        $dispatch = function (self $a): void {
            if ($a->thumb_path === null && Thumbnailer::supports($a)) {
                MakeThumbnail::dispatch($a->id);
            }
        };
        static::created($dispatch);
        static::updated(fn (self $a) => $a->wasChanged(['status', 'path']) ? $dispatch($a) : null);
    }

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
