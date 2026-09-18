<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agent rebuild §5: an intent can start a guided flow (`bot_intents.flow_key`).
 * The seed only fills rows whose flow_key is still null, so an owner's choice
 * (including a different flow) is never overwritten.
 */
return new class extends Migration
{
    private const FLOW_KEYS = [
        'exchange_return' => 'return_exchange',
        'defect' => 'return_exchange',
        'wrong_item' => 'return_exchange',
        'missing_item' => 'return_exchange',
        'refund' => 'return_exchange',
        'store_complaint' => 'complaint',
        'branch_issue' => 'complaint',
        'delivery_problem' => 'complaint',
        'cancel_order' => 'cancel_edit',
        'edit_order' => 'cancel_edit',
        'order_status' => 'order_tracking',
        'delayed_order' => 'order_tracking',
        'no_update' => 'order_tracking',
        'branches_hours' => 'branches',
        'availability_branch' => 'branches',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('bot_intents')) {
            return;
        }

        if (! Schema::hasColumn('bot_intents', 'flow_key')) {
            Schema::table('bot_intents', function (Blueprint $t) {
                $t->string('flow_key', 60)->nullable()->after('route');
            });
        }

        foreach (self::FLOW_KEYS as $intent => $flow) {
            DB::table('bot_intents')->where('key', $intent)->whereNull('flow_key')->update(['flow_key' => $flow]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bot_intents', 'flow_key')) {
            Schema::table('bot_intents', function (Blueprint $t) {
                $t->dropColumn('flow_key');
            });
        }
    }
};
