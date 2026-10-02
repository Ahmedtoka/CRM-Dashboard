<?php

namespace App\Ads\Materials;

use App\Media\MediaInspector;
use App\Media\MediaStorage;
use App\Media\Thumbnailer;
use App\Models\AdMaterial;
use App\Models\AdMaterialFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * Stores a material's files on the media disk under ad-materials/{Y}/{m}/{uuid}.{ext}. The type is the
 * file's real (sniffed) mime, never the client's extension or Content-Type. Images get a WebP thumbnail,
 * videos a poster frame at 1 s when ffmpeg is available (else none).
 */
final class MaterialFileStorage
{
    public const MIMES = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
        'video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm',
    ];

    private const THUMB_DIR = 'ad-materials/thumbs';

    public function __construct(private readonly MediaInspector $inspector, private readonly Thumbnailer $thumbnailer) {}

    public function disk(): string
    {
        return (string) config('crm.media.disk', 'media');
    }

    /** The sniffed mime when it is on the allowlist, else null. */
    public function allowedMime(UploadedFile $file): ?string
    {
        $real = (string) $file->getRealPath();
        if ($real === '' || ! is_file($real)) {
            return null;
        }
        $mime = $this->inspector->sniff($real, null, null);

        return isset(self::MIMES[$mime]) ? $mime : null;
    }

    /** 'image' | 'video' | null for a mime. */
    public static function kind(?string $mime): ?string
    {
        return match (true) {
            $mime !== null && str_starts_with($mime, 'image/') => 'image',
            $mime !== null && str_starts_with($mime, 'video/') => 'video',
            default => null,
        };
    }

    /** Size limit in bytes for the file's kind. */
    public function maxBytes(string $kind): int
    {
        return (int) config("crm.ads.material_max_mb.{$kind}", $kind === 'video' ? 500 : 20) * 1024 * 1024;
    }

    /** @throws \InvalidArgumentException when the mime or size is not allowed (callers validate first) */
    public function store(AdMaterial $m, UploadedFile $f): AdMaterialFile
    {
        $mime = $this->allowedMime($f);
        $kind = self::kind($mime);
        if ($mime === null || $kind === null || (int) $f->getSize() > $this->maxBytes($kind)) {
            throw new \InvalidArgumentException('file type or size not allowed');
        }

        $disk = $this->disk();
        $path = sprintf('ad-materials/%s/%s.%s', now()->format('Y/m'), Str::uuid(), self::MIMES[$mime]);
        $stream = fopen((string) $f->getRealPath(), 'rb');
        try {
            Storage::disk($disk)->put($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        [$w, $h] = $this->inspector->dimensions((string) $f->getRealPath(), $mime);
        $thumb = $kind === 'image'
            ? $this->thumbnailer->thumbnailForPath($disk, $path, $mime, self::THUMB_DIR)
            : $this->poster($disk, $path);

        return $m->files()->create([
            'disk' => $disk, 'path' => $path, 'thumb_path' => $thumb, 'mime' => $mime, 'size' => (int) $f->getSize(),
            'width' => $w, 'height' => $h,
            'original_name' => MediaStorage::sanitizeFilename(Str::limit($f->getClientOriginalName(), 250, '')),
            'sort' => ((int) $m->files()->max('sort')) + 1,
        ]);
    }

    /** Removes the file and its thumbnail from disk (the row is the caller's). */
    public function deleteFromDisk(string $disk, ?string $path, ?string $thumb): void
    {
        foreach (array_filter([$path, $thumb]) as $p) {
            try {
                Storage::disk($disk)->delete($p);
            } catch (Throwable) {
                // a missing or unreachable object must not block deleting the material
            }
        }
    }

    /** A JPEG poster of the video at 1 s, or null (no ffmpeg, non-local disk, failure). */
    private function poster(string $disk, string $path): ?string
    {
        $ffmpeg = config('crm.media.ffmpeg_path');
        $ffmpeg = is_string($ffmpeg) && $ffmpeg !== '' ? $ffmpeg : (new ExecutableFinder)->find('ffmpeg');
        $storage = Storage::disk($disk);
        if ($ffmpeg === null || ! $this->isLocal($storage)) {
            return null;
        }

        $tmp = storage_path('app/tmp/'.Str::uuid().'.jpg');
        try {
            File::ensureDirectoryExists(dirname($tmp));
            $result = Process::timeout(30)->run([
                $ffmpeg, '-y', '-ss', '1', '-i', $storage->path($path), '-frames:v', '1',
                '-vf', 'scale=min(480\,iw):-2', $tmp,
            ]);
            if (! $result->successful() || ! is_file($tmp)) {
                // A clip shorter than 1 s has no frame there: retry from the start.
                $result = Process::timeout(30)->run([$ffmpeg, '-y', '-i', $storage->path($path), '-frames:v', '1', '-vf', 'scale=min(480\,iw):-2', $tmp]);
            }
            if (! $result->successful() || ! is_file($tmp)) {
                return null;
            }
            $out = sprintf('%s/%s/%s.jpg', self::THUMB_DIR, now()->format('Y/m'), Str::uuid());
            $storage->put($out, (string) file_get_contents($tmp));

            return $out;
        } catch (Throwable) {
            return null;
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private function isLocal(Filesystem $storage): bool
    {
        return $storage instanceof FilesystemAdapter && $storage->getAdapter() instanceof LocalFilesystemAdapter;
    }
}
