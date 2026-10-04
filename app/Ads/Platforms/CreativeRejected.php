<?php

namespace App\Ads\Platforms;

/**
 * A publish failed BEFORE the ad-create request was sent (identity, thumbnail, cover upload, creative step, a usage
 * back-off): no ad can exist on the platform, so the caller must not warn that the ad "may already exist". When the
 * previous exception is a RateLimited, the caller may retry later.
 */
class CreativeRejected extends AdsApiException
{
    public static function from(AdsApiException $e): self
    {
        return $e instanceof self ? $e : new self($e->getMessage(), (int) $e->getCode(), $e);
    }

    public function rateLimited(): bool
    {
        return $this->getPrevious() instanceof RateLimited;
    }
}
