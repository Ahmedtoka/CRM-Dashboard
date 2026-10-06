<?php

namespace App\Ads\Launch;

use App\Models\AdLaunch;
use Illuminate\Foundation\Events\Dispatchable;

/** Fired after every launch transition (material status, Task 12). */
final class LaunchMoved
{
    use Dispatchable;

    public function __construct(public readonly AdLaunch $launch, public readonly ?LaunchState $from, public readonly LaunchState $to) {}
}
