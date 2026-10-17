<?php

namespace App\Shopify\App;

use RuntimeException;

/**
 * The store was rolled back to the manual connection: opening the app in Shopify admin must not
 * silently move it onto the app again (review H2). The owner re-allows it from Settings first.
 */
final class ManualConnectionActive extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The store was rolled back to the manual connection; allow the app again from Settings.');
    }
}
