<?php

namespace App\Ads;

/**
 * The naming convention of Ads v2. The system only names the ads it creates (`M{material} | {Type} | C{n}`);
 * campaign and ad set names are checked against the convention so the UI can flag the ones that do not follow it.
 */
final class Naming
{
    public const TYPES = ['Reel', 'Image', 'Carousel', 'Post', 'Story', 'Video'];

    /** `LV | Product | Objective | Buyer | YYMMDD` */
    public static function checkCampaign(string $name): bool
    {
        return preg_match('/^LV \| .+ \| .+ \| .+ \| \d{6}\z/u', $name) === 1;
    }

    /** `Broad | EG | Advantage+` — three parts. */
    public static function checkAdSet(string $name): bool
    {
        return preg_match('/^.+ \| .+ \| .+\z/u', $name) === 1;
    }

    /** `M{material id} | {Type} | C{caption n}`; an unknown type reads as Post. */
    public static function adName(int $materialId, string $type, int $caption): string
    {
        $type = ucfirst(strtolower(trim($type)));
        if (! in_array($type, self::TYPES, true)) {
            $type = 'Post';
        }

        return "M{$materialId} | {$type} | C{$caption}";
    }
}
