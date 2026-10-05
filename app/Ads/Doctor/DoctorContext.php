<?php

namespace App\Ads\Doctor;

/** What the operator asked for: whether the checks may call Meta (GET only) and which accounts to look at. */
final class DoctorContext
{
    /** @param  list<string>  $accounts  ad_accounts ids or external ids; empty = all */
    public function __construct(public readonly bool $network = true, public readonly array $accounts = []) {}
}
