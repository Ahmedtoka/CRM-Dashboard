<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Superseded the same day by 2026_09_19_300010_publish_owner_return_exchange_flow
 * (the owner's own return/exchange flow, which already has the ownership proof and
 * the `order_items` steps). Kept as a no-op so databases that ran it stay consistent.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Nothing: see 2026_09_19_300010_publish_owner_return_exchange_flow.
    }

    public function down(): void
    {
        // Nothing.
    }
};
