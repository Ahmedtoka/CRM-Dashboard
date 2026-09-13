<?php

namespace App\Enums;

enum Platform: string
{
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case WhatsApp = 'whatsapp';
    case TikTok = 'tiktok';

    public function label(): string
    {
        return match ($this) {
            self::Facebook => 'Messenger',
            self::Instagram => 'Instagram',
            self::WhatsApp => 'WhatsApp',
            self::TikTok => 'TikTok',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Facebook => '#0866FF',
            self::Instagram => '#E1306C',
            self::WhatsApp => '#25D366',
            self::TikTok => '#000000',
        };
    }
}
