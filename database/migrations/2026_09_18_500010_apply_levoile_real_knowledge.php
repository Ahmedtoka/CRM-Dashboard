<?php

use App\Bot\Knowledge\RealKnowledgeApplier;
use Illuminate\Database\Migrations\Migration;

/**
 * Data-only and idempotent: Le Voile's real policy knowledge replaces the
 * placeholder samples ONLY on rows the owner never touched (still flagged
 * is_template and still the old sample text); fills the ❓ payment_info
 * script; adds the shipping_cost intent + script. See RealKnowledgeApplier.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(RealKnowledgeApplier::class)->apply();
    }

    public function down(): void
    {
        // Data update: left in place (the owner may have edited it since).
    }
};
