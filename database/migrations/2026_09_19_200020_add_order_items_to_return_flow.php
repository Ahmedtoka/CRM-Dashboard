<?php

use App\Bot\Flows\ReturnFlowUpgrade;
use Illuminate\Database\Migrations\Migration;

/**
 * Data-only (spec 2026-09-19 §3): the return/exchange flow asks for proof of
 * ownership and lets her pick the order's items. The live flow is updated only
 * while it is still the seeded definition; an owner-edited flow gets the change
 * as a draft to publish. Idempotent: a flow that already has the step is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        ReturnFlowUpgrade::run();
    }

    public function down(): void
    {
        // Data change: the previous version stays in the flow's history and can be restored from the designer.
    }
};
