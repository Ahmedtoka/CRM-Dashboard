<?php

namespace App\Ads\Platforms\Data;

use Closure;

final class AdDraft
{
    /**
     * @param  string|null  $thumbnailUrl  a public image for a video ad (Meta image_url, TikTok cover uploaded by URL)
     * @param  string|null  $posterDisk  with $posterPath: a local poster frame of the video, uploaded when no URL works
     * @param  Closure(): void|null  $beforeAdRequest  called by the writer right before the ad-create request is sent
     */
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
        public ?string $posterDisk = null,
        public ?string $posterPath = null,
        public ?Closure $beforeAdRequest = null,
    ) {}

    /** Writers call this immediately before sending the ad-create request; from then on a failure may leave an ad behind. */
    public function adRequestSending(): void
    {
        if ($this->beforeAdRequest !== null) {
            ($this->beforeAdRequest)();
        }
    }
}
