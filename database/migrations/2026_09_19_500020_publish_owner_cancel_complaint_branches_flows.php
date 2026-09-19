<?php

use App\Bot\Flows\OwnerFlowsUpgrade;
use Illuminate\Database\Migrations\Migration;

/**
 * Data-only (2026-09-19): publishes the owner's cancel/edit, complaint and branches
 * flows as new versions (OwnerFlowsUpgrade) — the previous published version and any
 * draft are archived (restorable from the designer's history) — and adds the missing
 * «كلم موظف» scripts (handover_ask_topic, handover_in_hours, handover_after_hours,
 * handover_no_hours). Idempotent: re-running changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        OwnerFlowsUpgrade::run();
    }

    public function down(): void
    {
        // Data change: the previous versions stay in each flow's history and can be restored from the designer.
    }
};
