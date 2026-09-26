<?php

namespace App\Shopify\Sync\Mappers;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Out-of-order delivery protection (spec §4.2 point 5): a payload whose `updated_at`
 * is not strictly newer than what we stored is ignored.
 */
final class StaleGuard
{
    /**
     * True when incoming <= stored, compared as UTC instants at second precision.
     * A never-synced record (stored null) or a payload without a timestamp is never stale.
     */
    public static function isStale(?CarbonInterface $stored, ?string $incomingIso): bool
    {
        if ($stored === null || $incomingIso === null || trim($incomingIso) === '') {
            return false;
        }

        try {
            $incoming = CarbonImmutable::parse($incomingIso);
        } catch (Throwable) {
            return false;
        }

        return $incoming->getTimestamp() <= $stored->getTimestamp();
    }

    /**
     * True only when incoming is strictly older than stored. A bulk re-import
     * re-applies the same version (equal timestamps) so columns added since the
     * row was first stored get filled; an older copy is still never applied.
     */
    public static function isOlder(?CarbonInterface $stored, ?string $incomingIso): bool
    {
        if ($stored === null || $incomingIso === null || trim($incomingIso) === '') {
            return false;
        }

        try {
            $incoming = CarbonImmutable::parse($incomingIso);
        } catch (Throwable) {
            return false;
        }

        return $incoming->getTimestamp() < $stored->getTimestamp();
    }
}
