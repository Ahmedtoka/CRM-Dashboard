<?php

namespace App\Ads\Platforms;

/** A write was stopped before any platform call; $reason is the key under ads.errors. */
class WriteRefused extends AdsApiException
{
    public function __construct(public string $reason)
    {
        parent::__construct($reason);
    }
}
