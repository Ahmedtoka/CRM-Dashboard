<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data-only and idempotent (overnight refinement change 4): the out-of-stock
 * script now points the customer at the available models instead of leaving
 * her with nothing to do (the scarves-link swap in TurnRunner::bodies()
 * applies to this script too). Only while the row still carries the
 * original seeded body; an owner edit is left in place.
 */
return new class extends Migration
{
    private const OLD_BODY = 'للاسف حاليا المنتج غير متاح تابعينا دائما و بمجرد ما يتوفر بيكون متاح علي الويب سايت';

    private const NEW_BODY = "للاسف حاليا المنتج غير متاح تابعينا دائما و بمجرد ما يتوفر بيكون متاح علي الويب سايت\nتقدري تشوفي الموديلات المتاحة من هنا 👇\nhttps://levoilestores.com/";

    public function up(): void
    {
        if (! Schema::hasTable('bot_knowledge_entries')) {
            return;
        }

        DB::table('bot_knowledge_entries')
            ->where('key', 'script.not_available')
            ->where('body', self::OLD_BODY)
            ->update(['body' => self::NEW_BODY, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Data fix: left in place (the owner may have edited it since).
    }
};
