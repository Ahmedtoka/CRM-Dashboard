<?php

use App\Bot\Flows\ReturnFlowUpgrade;
use Illuminate\Database\Migrations\Migration;

/**
 * Bot hybrid P1 (bot-hybrid.md §3.7, owner B4): the return/exchange flow skips the kind question when
 * her message already said it, takes a return and an exchange of one order as one request, and asks a
 * replacement for every exchanged piece. Published as a new version (the old one stays restorable);
 * every older step key is kept, so a chat already inside the flow carries on.
 */
return new class extends Migration
{
    public function up(): void
    {
        ReturnFlowUpgrade::publish(ReturnFlowUpgrade::FLOW_KEY, ReturnFlowUpgrade::definition(), ReturnFlowUpgrade::NOTE_2026_10_17);
    }

    public function down(): void
    {
        // The previous version stays in the flow's history: restore it from the flow designer.
    }
};
