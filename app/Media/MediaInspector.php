<?php

namespace App\Media;

/**
 * Sniffs the real mime type of a file from its magic bytes (never trusting
 * the client-supplied Content-Type or filename extension alone — spec §1,
 * "Security": uploads are validated by sniffed mime).
 */
final class MediaInspector
{
    /**
     * Maps a canonical mime type to the file extension used when generating
     * storage paths (see MediaStorage::relativePath()).
     */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'video/mp4' => 'mp4',
        'video/3gpp' => '3gp',
        'video/quicktime' => 'mov',
        'audio/ogg' => 'ogg',
        'audio/opus' => 'ogg',
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'audio/aac' => 'aac',
        'audio/webm' => 'webm',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'text/csv' => 'csv',
        'text/plain' => 'txt',
        'application/zip' => 'zip',
    ];

    /**
     * ISO-BMFF ("ftyp") major brands that unambiguously identify a container
     * format regardless of the client-declared mime — read from bytes 8-12
     * (after the 4-byte box size and the "ftyp" box type itself).
     */
    private const FTYP_VIDEO_MP4_BRANDS = ['isom', 'mp41', 'mp42', 'avc1'];

    private const FTYP_HEIC_FAMILY_BRANDS = ['heic', 'heix', 'mif1', 'avif'];

    public function sniff(string $absolutePath, ?string $clientMime = null, ?string $extension = null): string
    {
        $detected = $this->finfoMime($absolutePath);
        $head = (string) (@file_get_contents($absolutePath, false, null, 0, 16) ?: '');

        // 1. Magic-byte overrides that finfo commonly gets wrong or too generic.
        if (str_starts_with($head, 'OggS')) {
            return 'audio/ogg';
        }
        if (strlen($head) >= 8 && substr($head, 4, 4) === 'ftyp') {
            $mapped = $this->sniffFtypBrand(strlen($head) >= 12 ? substr($head, 8, 4) : '', $clientMime);

            if ($mapped !== null) {
                return $mapped;
            }
            // heic/heix/mif1/avif (still-image HEIC/AVIF containers): fall through
            // to finfo below rather than guessing a video/audio mime for them.
        }
        if (str_starts_with($head, '%PDF')) {
            return 'application/pdf';
        }
        if (str_starts_with($head, "\x89PNG")) {
            return 'image/png';
        }

        // 2. finfo-value normalisation, informed by the client mime/extension.
        if ($detected === 'application/ogg') {
            return 'audio/ogg';
        }
        if ($detected === 'video/webm' && $clientMime !== null && str_starts_with($clientMime, 'audio/')) {
            return 'audio/webm';
        }
        $ext = $extension !== null ? strtolower($extension) : null;
        if ($detected === 'application/zip' && $ext === 'docx') {
            return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        }
        if ($detected === 'application/zip' && $ext === 'xlsx') {
            return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        }
        if ($detected === 'text/plain' && $ext === 'csv') {
            return 'text/csv';
        }

        // 3. Otherwise trust finfo.
        return $detected;
    }

    /**
     * Maps an ISO-BMFF major brand to a mime, or null to fall through to
     * finfo (unknown brand, or a still-image HEIC/AVIF family brand — those
     * aren't in any configured type's mime list, so MediaPolicy naturally
     * rejects them once finfo reports its own value for them).
     */
    private function sniffFtypBrand(string $brand, ?string $clientMime): ?string
    {
        return match (true) {
            $brand === 'qt  ' => 'video/quicktime',
            str_starts_with($brand, '3gp') => 'video/3gpp',
            $brand === 'M4A ' => 'audio/mp4',
            in_array($brand, self::FTYP_VIDEO_MP4_BRANDS, true) => 'video/mp4',
            in_array($brand, self::FTYP_HEIC_FAMILY_BRANDS, true) => null,
            default => in_array($clientMime, ['audio/mp4', 'audio/x-m4a', 'audio/aac'], true) ? 'audio/mp4' : 'video/mp4',
        };
    }

    public function extensionFor(string $mime): string
    {
        return self::EXTENSIONS[$mime] ?? 'bin';
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    public function dimensions(string $absolutePath, string $mime): array
    {
        if (! str_starts_with($mime, 'image/')) {
            return [null, null];
        }

        $size = @getimagesize($absolutePath);

        return $size ? [(int) $size[0], (int) $size[1]] : [null, null];
    }

    private function finfoMime(string $absolutePath): string
    {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return 'application/octet-stream';
        }

        $mime = @finfo_file($finfo, $absolutePath);
        finfo_close($finfo);

        return trim(explode(';', (string) ($mime ?: 'application/octet-stream'))[0]);
    }
}
