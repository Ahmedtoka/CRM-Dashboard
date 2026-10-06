<?php

namespace Tests\Support;

use App\Ads\Launch\LandingProbe;

/** No outbound HTTP in tests: the landing status is whatever the test says (O6). */
final class FakeLandingProbe implements LandingProbe
{
    public static ?int $status = 200;

    /** @var list<string> */
    public static array $urls = [];

    public function status(string $url): ?int
    {
        self::$urls[] = $url;

        return self::$status;
    }
}
