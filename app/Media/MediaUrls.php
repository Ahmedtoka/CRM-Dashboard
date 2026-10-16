<?php

namespace App\Media;

use App\Enums\AttachmentType;
use App\Models\MessageAttachment;
use App\Models\QuickReplyAttachment;
use Illuminate\Support\Facades\URL;

/**
 * URL helpers shared by AttachmentResource, the simulator, and the future
 * upload/send UI (Task 3) so nothing hand-builds a media route.
 */
final class MediaUrls
{
    public static function show(MessageAttachment $a): string
    {
        return route('media.show', $a, false);
    }

    /** The small grid image when one exists, else the original (`media.thumb` itself falls back too). */
    public static function thumb(MessageAttachment $a): ?string
    {
        if (! $a->isStored() || ! in_array($a->type, [AttachmentType::Image, AttachmentType::Sticker], true)) {
            return null;
        }

        return $a->thumb_path !== null ? route('media.thumb', $a, false) : self::show($a);
    }

    /**
     * Whether the current request is a mobile API call (`api/*`): those get absolute signed
     * temporary URLs, because a bearer-token client (image cache, video player, "open file")
     * cannot carry the web session the `/media/{id}` routes need (API contract, "Media").
     */
    public static function signedForRequest(): bool
    {
        return self::$sessionDepth === 0 && app()->bound('request') && request()->is('api/*');
    }

    private static int $sessionDepth = 0;

    /**
     * Runs $build with web-style (relative session) URLs even inside an api/* request — for
     * payloads that are not the API response itself, e.g. a broadcast event built while a
     * mobile request is being handled, which web clients receive too.
     *
     * @template T
     *
     * @param  \Closure(): T  $build
     * @return T
     */
    public static function sessionUrls(\Closure $build): mixed
    {
        self::$sessionDepth++;

        try {
            return $build();
        } finally {
            self::$sessionDepth--;
        }
    }

    /** The attachment's url for this request: signed absolute on `api/*`, the session route otherwise. Null until stored. */
    public static function forRequest(MessageAttachment $a): ?string
    {
        if (! $a->isStored()) {
            return null;
        }

        return self::signedForRequest() ? self::signedShow($a) : self::show($a);
    }

    /** thumb() for this request: signed absolute on `api/*`, the session route otherwise. */
    public static function thumbForRequest(MessageAttachment $a): ?string
    {
        return self::signedForRequest() ? self::signedThumb($a) : self::thumb($a);
    }

    public static function signedShow(MessageAttachment $a): ?string
    {
        if (! $a->isStored()) {
            return null;
        }

        return URL::temporarySignedRoute('media.signed', self::mobileExpiry(), ['attachment' => $a->id]);
    }

    public static function signedThumb(MessageAttachment $a): ?string
    {
        if (! $a->isStored() || ! in_array($a->type, [AttachmentType::Image, AttachmentType::Sticker], true)) {
            return null;
        }

        return $a->thumb_path !== null
            ? URL::temporarySignedRoute('media.signed.thumb', self::mobileExpiry(), ['attachment' => $a->id])
            : self::signedShow($a);
    }

    public static function signedQuickReply(QuickReplyAttachment $a): string
    {
        return URL::temporarySignedRoute('quick-reply-attachments.signed', self::mobileExpiry(), ['attachment' => $a->id]);
    }

    /** A saved reply's own attachment preview for this request (images only, like the web picker). */
    public static function quickReplyThumbForRequest(QuickReplyAttachment $a): ?string
    {
        if ($a->type !== AttachmentType::Image) {
            return null;
        }

        return self::signedForRequest() ? self::signedQuickReply($a) : route('quick-reply-attachments.show', $a, false);
    }

    private static function mobileExpiry(): \DateTimeInterface
    {
        return now()->addMinutes((int) config('crm.mobile.media_url_ttl_minutes', 120));
    }

    public static function temporaryPublic(MessageAttachment $a): string
    {
        return URL::temporarySignedRoute('media.public', now()->addMinutes((int) config('crm.media.signed_url_minutes', 60)), ['attachment' => $a->id]);
    }
}
