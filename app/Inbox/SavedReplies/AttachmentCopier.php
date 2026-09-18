<?php

namespace App\Inbox\SavedReplies;

use App\Enums\AttachmentStatus;
use App\Enums\AttachmentType;
use App\Media\MediaRejected;
use App\Media\MediaStorage;
use App\Models\MessageAttachment;
use App\Models\QuickReplyAttachment;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Copies a saved reply's stored attachment into a fresh, unlinked outbound
 * upload (spec §2.1): deleting or editing the reply afterwards never breaks
 * history, and an unsent copy the agent doesn't send is later pruned by
 * `crm:prune-media-orphans`, same as any other unsent upload.
 */
final class AttachmentCopier
{
    public function __construct(private readonly MediaStorage $storage) {}

    public function copy(
        string $disk,
        string $path,
        AttachmentType $type,
        ?string $mime,
        ?string $name,
        ?int $width,
        ?int $height,
        ?int $size,
        ?User $user,
    ): MessageAttachment {
        $target = $this->storage->relativePath('outbound', (string) $mime);
        $stream = Storage::disk($disk)->readStream($path) ?? throw new MediaRejected('الملف الأصلي مش موجود');
        try {
            $written = Storage::disk($this->storage->disk())->writeStream($target, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        // The media disk is configured with `throw => false` (never throws on
        // its own): a failed write returns false instead, which would
        // otherwise leave a "Stored" row pointing at a file that was never
        // actually written. Clean up and refuse instead.
        if ($written === false) {
            Storage::disk($this->storage->disk())->delete($target);

            throw new MediaRejected('تعذر نسخ الملف المرفق');
        }

        return MessageAttachment::create([
            'uploaded_by' => $user?->id, 'type' => $type, 'disk' => $this->storage->disk(), 'path' => $target, 'mime' => $mime,
            'size_bytes' => $size, 'original_name' => MediaStorage::sanitizeFilename($name),
            'width' => $width, 'height' => $height, 'status' => AttachmentStatus::Stored,
        ]);
    }

    public function toOutbound(QuickReplyAttachment $source, User $user): MessageAttachment
    {
        return $this->copy($source->disk, $source->path, $source->type, $source->mime, $source->original_name,
            $source->width, $source->height, $source->size_bytes, $user);
    }
}
