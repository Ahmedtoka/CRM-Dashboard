<?php

namespace App\Ads\Platforms\Data;

final class AdDraft
{
    public function __construct(
        public string $adSetId,
        public string $name,
        public Identity $identity,
        public MediaRef $media,
        public string $primaryText,
        public string $headline,
        public string $cta,
        public string $link,
        public string $urlTags,
        public ?string $thumbnailUrl = null,
    ) {}
}
