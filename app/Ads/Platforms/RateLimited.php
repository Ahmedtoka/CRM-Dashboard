<?php

namespace App\Ads\Platforms;

/** The platform quota is (nearly) used up; the caller should retry later. */
class RateLimited extends AdsApiException {}
