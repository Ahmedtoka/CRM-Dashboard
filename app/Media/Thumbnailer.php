<?php

namespace App\Media;

use App\Enums\AttachmentType;
use App\Models\MessageAttachment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Inbox grid thumbnails (UI overhaul Task 4b): longest side 480 px, aspect kept, never upscaled,
 * WebP quality 78 (JPEG 80 when GD has no WebP, R6), stored beside the original on the same disk
 * under thumbs/<yyyy>/<mm>/<uuid>.<ext>. Alpha is kept for stickers. A decode failure is logged and
 * returns null: the original keeps being served, the inbox never breaks over a thumbnail.
 */
final class Thumbnailer
{
    public const MAX_SIDE = 480;

    /**
     * Refuse to decode anything above this many pixels (a decoded GD bitmap is ~4-5 bytes per pixel,
     * so 24 MP stays near 120 MB, inside a 256 MB worker's memory_limit with the bytes and the thumb).
     */
    public const MAX_PIXELS = 24_000_000;

    public static function supports(MessageAttachment $a): bool
    {
        return $a->isStored() && in_array($a->type, [AttachmentType::Image, AttachmentType::Sticker], true);
    }

    /** Makes and records the thumbnail; returns its relative path, or null when none could be made. */
    public function make(MessageAttachment $a): ?string
    {
        if (! self::supports($a) || ! function_exists('imagecreatefromstring')) {
            return null;
        }

        try {
            $path = $this->render((string) $a->disk, (string) $a->path);
            if ($path === null) {
                return null;
            }
            $a->forceFill(['thumb_path' => $path])->save();

            return $path;
        } catch (Throwable $e) {
            Log::warning('media.thumbnail_failed', ['attachment_id' => $a->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Thumbnail of any stored image, by disk + path (not tied to a MessageAttachment): returns the new
     * relative path under `$dir` on the same disk, or null for a non-image, a missing file or a decode failure.
     */
    public function thumbnailForPath(string $disk, string $path, string $mime, string $dir = 'thumbs'): ?string
    {
        if (! str_starts_with($mime, 'image/') || ! function_exists('imagecreatefromstring')) {
            return null;
        }

        try {
            return $this->render($disk, $path, $dir);
        } catch (Throwable $e) {
            Log::warning('media.thumbnail_failed', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** @throws Throwable on a decode/encode failure; null when the source file is missing */
    private function render(string $diskName, string $sourcePath, string $dir = 'thumbs'): ?string
    {
        $disk = Storage::disk($diskName);
        $source = null;
        $thumb = null;

        try {
            if (! $disk->exists($sourcePath)) {
                return null;
            }
            $bytes = (string) $disk->get($sourcePath);
            $info = @getimagesizefromstring($bytes);
            if ($info === false || $info[0] < 1 || $info[1] < 1) {
                throw new \RuntimeException('not a decodable image');
            }
            if ($info[0] * $info[1] > self::MAX_PIXELS) {
                throw new \RuntimeException('image above the pixel cap');
            }
            $source = @imagecreatefromstring($bytes);
            if ($source === false) {
                throw new \RuntimeException('GD could not decode the image');
            }

            $source = $this->oriented($source, $bytes, $info['mime'] ?? '');
            [$w, $h] = [imagesx($source), imagesy($source)];
            $scale = min(1.0, self::MAX_SIDE / max($w, $h));
            [$tw, $th] = [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];

            $thumb = imagecreatetruecolor($tw, $th);
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            imagefill($thumb, 0, 0, (int) imagecolorallocatealpha($thumb, 255, 255, 255, 127));
            imagecopyresampled($thumb, $source, 0, 0, 0, 0, $tw, $th, $w, $h);

            $webp = function_exists('imagewebp');
            if (! $webp) {
                // JPEG has no alpha: flatten onto white.
                $flat = imagecreatetruecolor($tw, $th);
                imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
                imagecopy($flat, $thumb, 0, 0, 0, 0, $tw, $th);
                imagedestroy($thumb);
                $thumb = $flat;
            }

            ob_start();
            $ok = $webp ? imagewebp($thumb, null, 78) : imagejpeg($thumb, null, 80);
            $out = (string) ob_get_clean();
            if (! $ok || $out === '') {
                throw new \RuntimeException('GD could not encode the thumbnail');
            }

            $path = sprintf('%s/%s/%s.%s', $dir, now()->format('Y/m'), Str::uuid(), $webp ? 'webp' : 'jpg');
            $disk->put($path, $out);

            return $path;
        } finally {
            if ($source instanceof \GdImage) {
                imagedestroy($source);
            }
            if ($thumb instanceof \GdImage) {
                imagedestroy($thumb);
            }
        }
    }

    /** Applies the camera's EXIF orientation: the thumbnail carries no EXIF, so the browser could not. */
    private function oriented(\GdImage $img, string $bytes, string $mime): \GdImage
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $img;
        }
        $rotated = imagerotate($img, $angle, 0);

        return $rotated === false ? $img : $rotated;
    }
}
