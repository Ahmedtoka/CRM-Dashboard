<?php

namespace App\Ads\Attribution;

final class UtmParser
{
    public const KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

    /**
     * Reads the utm_* parameters of a landing page url or path ("/?utm_source=x" as Shopify REST sends it).
     *
     * @return array{utm_source:?string,utm_medium:?string,utm_campaign:?string,utm_content:?string,utm_term:?string}
     */
    public static function fromUrl(?string $url): array
    {
        $out = array_fill_keys(self::KEYS, null);
        $url = trim((string) $url);

        if ($url === '' || ! str_contains($url, '?')) {
            return $out;
        }

        $query = substr($url, strpos($url, '?') + 1);
        if (($hash = strpos($query, '#')) !== false) {
            $query = substr($query, 0, $hash);
        }

        parse_str($query, $params);

        foreach ($params as $name => $value) {
            $name = strtolower((string) $name);
            if (in_array($name, self::KEYS, true) && is_string($value) && trim($value) !== '') {
                $out[$name] = mb_substr(trim($value), 0, 255);
            }
        }

        return $out;
    }
}
