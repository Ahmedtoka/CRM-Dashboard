<?php

namespace App\Bot\Grounding;

/**
 * Reads colour and size out of a Shopify variant title such as "أحمر / M"
 * or "XL - black". Parts that are neither a known colour nor a size are
 * ignored; "Default Title" means a single-option product.
 */
final class VariantOptions
{
    /** @return array{color: ?string, size: ?string} */
    public static function parse(?string $variantTitle): array
    {
        $result = ['color' => null, 'size' => null];
        $title = trim((string) $variantTitle);

        if ($title === '' || in_array(mb_strtolower($title), ['default title', 'default'], true)) {
            return $result;
        }

        foreach (preg_split('/[\/|\-]/u', $title) ?: [] as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            if ($result['size'] === null && ($size = Synonyms::size($part)) !== null) {
                $result['size'] = $size;
            } elseif ($result['color'] === null && ($color = Synonyms::color($part)) !== null) {
                $result['color'] = $color;
            }
        }

        return $result;
    }
}
