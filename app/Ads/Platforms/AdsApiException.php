<?php

namespace App\Ads\Platforms;

use RuntimeException;

/** A platform answered with an error; the message is the platform's own text. */
class AdsApiException extends RuntimeException {}
