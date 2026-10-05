<?php

namespace App\Ads\Platforms;

/** The platform token is dead (expired, revoked, password changed): the connection needs a new token. */
class TokenInvalid extends AdsApiException {}
