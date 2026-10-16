<?php

namespace App\Channels;

/**
 * Whether the Meta webhook verify token (crm.meta.verify_token) can be trusted — the
 * one rule used by the GET handshake, the WhatsApp callback override and the hint on
 * Settings → Integrations (audit A2). Missing is never usable; in production the old
 * published default and anything shorter than MIN_LENGTH are refused too.
 */
final class VerifyToken
{
    public const OLD_DEFAULT = 'crm-verify';

    public const MIN_LENGTH = 16;

    /** @return 'missing'|'default'|'too_short'|null null when the token is usable */
    public static function problem(): ?string
    {
        $token = (string) config('crm.meta.verify_token');

        if ($token === '') {
            return 'missing';
        }

        if (! app()->environment('production')) {
            return null;
        }

        if ($token === self::OLD_DEFAULT) {
            return 'default';
        }

        return strlen($token) < self::MIN_LENGTH ? 'too_short' : null;
    }

    public static function usable(): bool
    {
        return self::problem() === null;
    }
}
