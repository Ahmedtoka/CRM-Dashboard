<?php

namespace App\Enums;

enum AttachmentType: string
{
    case Image = 'image';
    case Audio = 'audio';
    case Video = 'video';
    case File = 'file';
    case Sticker = 'sticker';

    /**
     * Whether the browser can render this attachment inline (Content-Disposition:
     * inline) instead of forcing a download. Files (documents) always download.
     */
    public function servesInline(): bool
    {
        return $this !== self::File;
    }
}
