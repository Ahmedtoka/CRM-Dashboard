<?php

namespace App\Ads\Platforms\Meta;

use App\Ads\Platforms\AdsApiException;

/** Meta refused a page as too large («Please reduce the amount of data…»); a smaller `limit` passes. */
class TooMuchData extends AdsApiException {}
