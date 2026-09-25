<?php

use App\Bot\Flows\OwnerFlowsUpgrade;
use App\Bot\Flows\ReturnFlowUpgrade;
use Illuminate\Database\Migrations\Migration;

/** Owner, 2026-09-26: the complaint's piece question is shorter now that the pieces come as pictures. Idempotent. */
return new class extends Migration
{
    public function up(): void
    {
        ReturnFlowUpgrade::publish('complaint', OwnerFlowsUpgrade::complaintDefinition(), OwnerFlowsUpgrade::NOTE_2026_09_26);
        (require __DIR__.'/2026_09_21_200040_seed_english_bot_translations.php')->up();
    }

    public function down(): void
    {
        // The previous version stays in bot_flow_versions.
    }
};
