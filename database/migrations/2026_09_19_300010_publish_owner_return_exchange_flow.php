<?php

use App\Bot\Flows\ReturnFlowUpgrade;
use Illuminate\Database\Migrations\Migration;

/**
 * Data-only (2026-09-19): publishes the owner's return/exchange flow as a new
 * version of `return_exchange` — "ترجعي ولا تبدلي؟" after the ownership proof,
 * a photo for a return, the store link of the replacement for an exchange. The
 * previous published version and any draft are archived (restorable from the
 * designer's history) and the draft is cleared. Idempotent.
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
