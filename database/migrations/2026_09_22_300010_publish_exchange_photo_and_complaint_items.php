<?php

use App\Bot\Flows\OwnerFlowsUpgrade;
use App\Bot\Flows\ReturnFlowUpgrade;
use Illuminate\Database\Migrations\Migration;

/**
 * The owner's additions of 2026-09-22: the exchange flow asks for a photo when the piece has a
 * defect and names the pieces before the replacement picker; the complaint flow shows the
 * verified order's pieces as cards. Published as new versions (the live ones are archived,
 * restorable); nothing happens when the live flow already matches.
 */
return new class extends Migration
{
    public function up(): void
    {
        ReturnFlowUpgrade::publish(ReturnFlowUpgrade::FLOW_KEY, ReturnFlowUpgrade::definition(), ReturnFlowUpgrade::NOTE_2026_09_22);
        ReturnFlowUpgrade::publish('complaint', OwnerFlowsUpgrade::complaintDefinition(), OwnerFlowsUpgrade::NOTE_2026_09_22);
    }

    public function down(): void
    {
        // The previous versions stay in bot_flow_versions and can be restored from the flow editor.
    }
};
