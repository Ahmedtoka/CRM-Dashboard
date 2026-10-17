<?php

namespace App\Shopify\App;

use RuntimeException;

/** A valid request from a store this app (or this CRM's single integration) does not serve. */
final class ShopNotAllowed extends RuntimeException
{
    public function __construct(public readonly string $shop)
    {
        parent::__construct("Shop {$shop} is not allowed here.");
    }
}
