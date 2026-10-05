<?php

namespace App\Ads\Control;

use App\Models\AdPublication;
use Illuminate\Support\Collection;
use RuntimeException;

/** A publish that would repeat an ad still in flight or published in the last 24 h (W2). */
final class DuplicatePublication extends RuntimeException
{
    /** @param Collection<int, AdPublication> $existing */
    public function __construct(public readonly Collection $existing)
    {
        parent::__construct(__('ads.publish.duplicate_in_flight'));
    }
}
