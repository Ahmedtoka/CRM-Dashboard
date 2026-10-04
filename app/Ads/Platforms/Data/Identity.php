<?php

namespace App\Ads\Platforms\Data;

final class Identity
{
    public function __construct(public string $pageId, public string $pageName, public ?string $instagramId) {}
}
