<?php

namespace App\Support;

use App\Enums\Platform;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Per-platform inbox broadcast channels (UI overhaul Task 4b). Every inbox event goes to the
 * all-platform `inbox` channel (supervisor+ only) and to `inbox.platform.<p>` for its platform,
 * so a moderator's socket only ever receives platforms she is allowed to see.
 */
final class InboxChannels
{
    public static function name(Platform|string $platform): string
    {
        return 'inbox.platform.'.($platform instanceof Platform ? $platform->value : $platform);
    }

    /** @return list<PrivateChannel> */
    public static function forPlatform(Platform|string|null $platform): array
    {
        return $platform === null ? [] : [new PrivateChannel(self::name($platform))];
    }

    /**
     * @param  iterable<Platform|string|null>  $platforms
     * @return list<PrivateChannel>
     */
    public static function forPlatforms(iterable $platforms): array
    {
        $names = [];
        foreach ($platforms as $p) {
            if ($p !== null) {
                $names[self::name($p)] = true;
            }
        }

        return array_map(fn (string $n) => new PrivateChannel($n), array_keys($names));
    }
}
