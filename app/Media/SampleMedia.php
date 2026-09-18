<?php

namespace App\Media;

use InvalidArgumentException;

/**
 * Tiny, real (magic-byte valid) media fixtures used by tests, the simulator,
 * and the demo seeder — no binary fixture files to keep in the repo.
 */
final class SampleMedia
{
    public const KINDS = ['image', 'voice', 'video', 'file'];

    public static function bytes(string $kind): string
    {
        return match ($kind) {
            'image' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAMAASsJTYQAAAAASUVORK5CYII='),
            // Ogg page header + OpusHead identification packet (19 bytes).
            'voice' => "OggS\x00\x02".str_repeat("\x00", 20)."\x01\x13OpusHead\x01\x01\x38\x01\x80\xbb\x00\x00\x00\x00\x00",
            'video' => "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom",
            'file' => "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n",
            default => throw new InvalidArgumentException("Unknown sample media [{$kind}]"),
        };
    }

    public static function mime(string $kind): string
    {
        return ['image' => 'image/png', 'voice' => 'audio/ogg', 'video' => 'video/mp4', 'file' => 'application/pdf'][$kind];
    }

    public static function filename(string $kind): string
    {
        return ['image' => 'sample.png', 'voice' => 'voice.ogg', 'video' => 'video.mp4', 'file' => 'catalog.pdf'][$kind];
    }
}
