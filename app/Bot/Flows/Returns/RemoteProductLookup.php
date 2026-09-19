<?php

namespace App\Bot\Flows\Returns;

use App\Models\Product;

/** A store product by its handle when it is not in the synced catalog yet (bound in BotServiceProvider). */
interface RemoteProductLookup
{
    /** The product, saved to the catalog, or null when the store has no product with that handle. */
    public function byHandle(string $handle): ?Product;
}
