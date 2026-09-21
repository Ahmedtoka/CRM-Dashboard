<?php

namespace App\Http\Support;

/**
 * Renders a stored `error` column in the reader's language.
 *
 * Send failures are written by a queued worker, so they cannot be translated
 * where they happen — the worker has no idea which staff member will read the
 * message. They store a translation key instead (`errors.media.*`), and this
 * resolves it when the row is serialised for a request. Anything that is not a
 * known key — a raw Graph API message, say — passes through untouched.
 */
final class StoredMessage
{
    public static function error(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        $translated = __($stored);

        return is_string($translated) ? $translated : $stored;
    }
}
