<?php

namespace App\Media;

use App\Enums\AttachmentType;
use App\Models\MessageAttachment;
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

    public static function thumb(MessageAttachment $a): ?string
    {
        return $a->isStored() && in_array($a->type, [AttachmentType::Image, AttachmentType::Sticker], true) ? self::show($a) : null;
    }

    public static function temporaryPublic(MessageAttachment $a): string
    {
        return URL::temporarySignedRoute('media.public', now()->addMinutes((int) config('crm.media.signed_url_minutes', 60)), ['attachment' => $a->id]);
    }
}
