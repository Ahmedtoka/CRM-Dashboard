<?php

namespace App\Media;

use App\Enums\AttachmentStatus;
use App\Enums\AttachmentType;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Writes media bytes to the `media` disk under a random UUID name (spec §1,
 * "Security": uploads stored under random UUID names) and records/updates
 * the MessageAttachment row.
 */
final class MediaStorage
{
    public function __construct(private readonly MediaInspector $inspector, private readonly MediaPolicy $policy) {}

    public function disk(): string
    {
        return (string) config('crm.media.disk', 'media');
    }

    public function relativePath(string $direction, string $mime): string
    {
        return sprintf('%s/%s/%s.%s', $direction, now()->format('Y/m'), Str::uuid(), $this->inspector->extensionFor($mime));
    }

    /**
     * A `/` or `\` in a customer- or platform-supplied filename makes
     * Symfony's `setContentDisposition()` throw when serving it back out
     * (turning a download into a 500). Strip both wherever a filename is
     * recorded, in addition to sanitising again defensively when serving.
     */
    public static function sanitizeFilename(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $clean = trim(str_replace(['/', '\\'], '_', $name));

        return $clean !== '' ? $clean : null;
    }

    public function storeUpload(UploadedFile $file, User $uploader): MessageAttachment
    {
        $real = (string) $file->getRealPath();
        $mime = $this->inspector->sniff($real, $file->getClientMimeType(), $file->getClientOriginalExtension());
        $type = $this->policy->assertUploadable($mime, (int) $file->getSize());
        $path = $this->relativePath('outbound', $mime);
        Storage::disk($this->disk())->put($path, (string) file_get_contents($real));
        [$w, $h] = $this->inspector->dimensions($real, $mime);

        return MessageAttachment::create([
            'uploaded_by' => $uploader->id, 'type' => $type, 'disk' => $this->disk(), 'path' => $path, 'mime' => $mime,
            'size_bytes' => (int) $file->getSize(), 'original_name' => self::sanitizeFilename(Str::limit($file->getClientOriginalName(), 250, '')),
            'width' => $w, 'height' => $h, 'status' => AttachmentStatus::Stored,
        ]);
    }

    public function storeBytes(MessageAttachment $a, string $bytes, ?string $mimeHint, ?string $filename): MessageAttachment
    {
        $tmp = tempnam(sys_get_temp_dir(), 'crm-media');
        file_put_contents($tmp, $bytes);
        try {
            $mime = $this->inspector->sniff($tmp, $mimeHint, $filename ? pathinfo($filename, PATHINFO_EXTENSION) : null);
            $path = $this->relativePath('inbound', $mime);
            Storage::disk($this->disk())->put($path, $bytes);
            [$w, $h] = $this->inspector->dimensions($tmp, $mime);
        } finally {
            @unlink($tmp);
        }
        $detected = $this->policy->typeFor($mime);

        $a->forceFill([
            'disk' => $this->disk(), 'path' => $path, 'mime' => $mime, 'size_bytes' => strlen($bytes),
            'type' => $a->type === AttachmentType::Sticker ? AttachmentType::Sticker : ($detected ?? $a->type),
            'original_name' => $a->original_name ?? self::sanitizeFilename($filename), 'width' => $w, 'height' => $h,
            'status' => AttachmentStatus::Stored, 'error' => null,
        ])->save();

        return $a;
    }
}
