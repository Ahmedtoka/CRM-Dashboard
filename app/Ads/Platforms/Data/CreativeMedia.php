<?php

namespace App\Ads\Platforms\Data;

final readonly class CreativeMedia
{
    public function __construct(public string $adExternalId, public ?string $imageUrl = null, public ?string $videoUrl = null, public ?string $thumbnailUrl = null, public ?string $previewUrl = null, public ?string $previewHtml = null, public ?string $permalinkUrl = null) {}
}
