<?php

namespace App\Media;

use App\Enums\AttachmentType;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Hardened file serving shared by every authorised media route (spec §1,
 * "Security"): private cache, sandboxed CSP, nosniff, and only the real
 * content-type for a mime that's actually on that type's allow-list —
 * otherwise a forced opaque octet-stream download. Callers are responsible
 * for authorisation and any existence/status check before calling this.
 */
final class MediaResponder
{
    public static function file(string $disk, string $path, AttachmentType $type, ?string $mime, ?string $originalName): BinaryFileResponse
    {
        abort_unless(Storage::disk($disk)->exists($path), 404);

        $name = MediaStorage::sanitizeFilename($originalName) ?: basename($path);
        $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', Str::ascii($name)) ?: 'file';

        // Only serve inline when the stored mime is actually one of the accepted
        // mimes for this type (config, read-only): a sniffed mime that doesn't
        // match — e.g. a spoofed "image" whose bytes are really text/html — is
        // forced to download as an opaque octet-stream instead of being
        // rendered inline by the browser. A mime ON the allow-list still gets
        // served with its real Content-Type even when the type itself never
        // serves inline (e.g. "file" documents always download, but a genuine
        // PDF still downloads as application/pdf rather than octet-stream).
        $allowedMimes = (array) config("crm.media.types.{$type->value}.mimes");
        $mimeAllowed = in_array($mime, $allowedMimes, true);
        $inline = $type->servesInline() && $mimeAllowed;
        $contentType = $mimeAllowed ? ($mime ?? 'application/octet-stream') : 'application/octet-stream';

        $response = response()->file(Storage::disk($disk)->path($path), [
            'Content-Type' => $contentType,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
            'Cache-Control' => 'private, max-age=3600',
        ]);
        // BinaryFileResponse defaults to a public cache (Symfony's $public
        // constructor arg defaults to true and calls setPublic() after our
        // headers are applied) — force it back to private here.
        $response->setPrivate();
        $response->setContentDisposition($inline ? 'inline' : 'attachment', $name, $fallback);

        return $response;
    }
}
