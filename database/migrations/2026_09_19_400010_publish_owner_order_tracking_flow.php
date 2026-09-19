<?php

use App\Bot\Flows\TrackingFlowUpgrade;
use Illuminate\Database\Migrations\Migration;

/**
 * Data-only (2026-09-19): publishes the owner's order-tracking flow as a new
 * version of `order_tracking` — the status card with the expected delivery,
 * [تمام شكرًا] [الأوردر اتأخر] [عايزة ألغي/أعدل] [كلم موظف], and a follow-up
 * case only when she says the order is late. The previous published version and
 * any draft are archived (restorable from the designer's history) and the draft
 * is cleared. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        TrackingFlowUpgrade::run();
    }

    public function down(): void
    {
        // Data change: the previous version stays in the flow's history and can be restored from the designer.
    }
};
