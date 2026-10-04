<?php

namespace App\Ads\Platforms\Data;

final class MediaRef
{
    /** @param  string  $kind  video|image */
    public function __construct(public string $kind, public string $id, public bool $ready) {}
}
