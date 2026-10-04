<?php

namespace App\Ads\Platforms\Data;

final class CampaignNode
{
    /** @param  list<array{id:string,name:string,status:?string}>  $adSets */
    public function __construct(public string $id, public string $name, public ?string $status, public ?string $objective, public array $adSets) {}
}
