<?php

namespace App\Ads\Launch;

/** The landing page's HTTP status (the `landing_http` warning, O6); null when it could not be read. */
interface LandingProbe
{
    public function status(string $url): ?int;
}
