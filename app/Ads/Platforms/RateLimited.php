<?php

namespace App\Ads\Platforms;

/** The platform quota is (nearly) used up; the caller should retry later (after $retryAfterSeconds when known). */
class RateLimited extends AdsApiException
{
    public function __construct(string $message = '', public ?int $retryAfterSeconds = null)
    {
        parent::__construct($message);
    }
}
