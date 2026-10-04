<?php

namespace App\Ads\Captions;

use App\Models\AdMaterialFile;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * Grabs JPEG frames (768 px wide) from a material video at 10/35/60/85 % of its duration, as base64 strings.
 * Empty list when ffmpeg is missing, the disk is not local or nothing could be read (captions then use product data only).
 */
class FrameExtractor
{
    private const POINTS = [0.10, 0.35, 0.60, 0.85];

    /** @return list<string> */
    public function frames(AdMaterialFile $f, int $count = 4): array
    {
        $ffmpeg = $this->binary('ffmpeg');
        $disk = Storage::disk($f->disk);
        if ($ffmpeg === null || ! ($disk instanceof FilesystemAdapter && $disk->getAdapter() instanceof LocalFilesystemAdapter) || ! $disk->exists($f->path)) {
            return [];
        }

        $source = $disk->path($f->path);
        $duration = (float) ($f->duration ?? 0);
        if ($duration <= 0) {
            $duration = $this->probe($source);
        }
        $times = $duration > 0
            ? array_map(fn (float $p) => round($duration * $p, 2), array_slice(self::POINTS, 0, max(1, min($count, count(self::POINTS)))))
            : [1.0];

        $out = [];
        foreach ($times as $t) {
            $frame = $this->grab($ffmpeg, $source, $t);
            if ($frame !== null) {
                $out[] = $frame;
            }
        }

        return $out;
    }

    private function grab(string $ffmpeg, string $source, float $t): ?string
    {
        $tmp = storage_path('app/tmp/'.Str::uuid().'.jpg');
        try {
            File::ensureDirectoryExists(dirname($tmp));
            $result = Process::timeout(30)->run([$ffmpeg, '-y', '-ss', (string) $t, '-i', $source, '-frames:v', '1', '-vf', 'scale=768:-2', '-q:v', '4', $tmp]);
            if (! $result->successful() || ! is_file($tmp) || filesize($tmp) === 0) {
                return null;
            }

            return base64_encode((string) file_get_contents($tmp));
        } catch (Throwable) {
            return null;
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /** Duration in seconds through ffprobe, 0 when unknown. */
    private function probe(string $source): float
    {
        $ffprobe = $this->binary('ffprobe');
        if ($ffprobe === null) {
            return 0.0;
        }
        try {
            $r = Process::timeout(15)->run([$ffprobe, '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $source]);

            return $r->successful() ? max(0.0, (float) trim($r->output())) : 0.0;
        } catch (Throwable) {
            return 0.0;
        }
    }

    private function binary(string $name): ?string
    {
        $configured = config('crm.media.ffmpeg_path');
        if (is_string($configured) && $configured !== '') {
            if ($name === 'ffmpeg') {
                return $configured;
            }
            $sibling = preg_replace('/ffmpeg(\.exe)?$/i', 'ffprobe$1', $configured);
            if (is_string($sibling) && $sibling !== $configured && is_file($sibling)) {
                return $sibling;
            }
        }

        return (new ExecutableFinder)->find($name);
    }
}
