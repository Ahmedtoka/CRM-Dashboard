<?php

namespace Tests\Support;

use App\Ads\Launch\LandingProbe;

/** No outbound HTTP in tests: the landing status is whatever the test says (O6). */
final class FakeLandingProbe implements LandingProbe
{
    public static ?int $status = 200;

    /** @var list<string> */
    public static array $urls = [];

    /** @var list<string> URLs probed live (an HTTP request in production) */
    public static array $live = [];

    public function status(string $url, bool $live = true): ?int
    {
        self::$urls[] = $url;
        if ($live) {
            self::$live[] = $url;
        }

        return self::$status;
    }

    public function known(string $url): bool
    {
        return true;
    }
}
