<?php

namespace App\Ads\Platforms;

/**
 * Transport failure (connect or read timeout, connection reset): the request may or may not have reached the platform,
 * so a write that ends here has an unknown outcome until it is read back.
 */
class PlatformUnreachable extends AdsApiException {}
