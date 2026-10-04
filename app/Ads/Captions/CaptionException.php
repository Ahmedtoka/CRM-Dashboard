<?php

namespace App\Ads\Captions;

use RuntimeException;

/** A caption run that cannot succeed (no key, bad answer); the message is already readable for the UI. */
class CaptionException extends RuntimeException {}
