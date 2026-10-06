<?php

namespace App\Ads\Launch;

/** The landing page's HTTP status (the `landing_http` warning, O6); null when it could not be read. */
interface LandingProbe
{
    /** Live = one HTTP request when nothing is cached; false = the cached answer only (lists never wait on the store). */
    public function status(string $url, bool $live = true): ?int;

    /** True when status() has an answer for the URL without a request. */
    public function known(string $url): bool;
}
