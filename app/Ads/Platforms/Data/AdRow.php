<?php

namespace App\Ads\Platforms\Data;

final readonly class AdRow
{
    public function __construct(
        public string $externalId, public string $name, public ?string $status, public ?string $effectiveStatus,
        public ?string $campaignId, public ?string $campaignName, public ?string $campaignStatus, public ?string $objective,
        public ?string $adSetId, public ?string $adSetName, public ?string $adSetStatus,
        public string $type,                       // image|video|carousel|dynamic
        public ?string $headline, public ?string $body, public ?string $thumbnailUrl, public ?string $imageUrl,
        public ?string $videoId, public ?string $objectStoryId, public ?string $instagramPermalinkUrl,
        public ?string $urlTags, public ?array $carousel, public ?string $createdTime, public array $raw = [],
    ) {}
}
