<?php

use App\Bot\Flows\ReturnFlowUpgrade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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
        $live = Schema::hasTable('bot_flows') ? DB::table('bot_flows')->where('key', ReturnFlowUpgrade::FLOW_KEY)->value('definition') : null;

        // Only over the version we published on 2026-09-22: an owner-edited flow is left as it is.
        if ($live === null || ! ReturnFlowUpgrade::same(json_decode((string) $live, true), ReturnFlowUpgrade::definitionBefore20261017())) {
            Log::info('bot_hybrid.return_flow_not_republished', ['reason' => $live === null ? 'no flow' : 'edited by the owner']);

            return;
        }

        ReturnFlowUpgrade::publish(ReturnFlowUpgrade::FLOW_KEY, ReturnFlowUpgrade::definition(), ReturnFlowUpgrade::NOTE_2026_10_17);
    }

    public function down(): void
    {
        // The previous version stays in the flow's history: restore it from the flow designer.
    }
};
