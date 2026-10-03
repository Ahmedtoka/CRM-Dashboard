<?php

namespace App\Ads\Platforms;

enum AdPlatform: string
{
    case Meta = 'meta';
    case Tiktok = 'tiktok';
    case Google = 'google';

    public function label(): string
    {
        return match ($this) {
            self::Meta => 'Meta',
            self::Tiktok => 'TikTok',
            self::Google => 'Google',
        };
    }
}
