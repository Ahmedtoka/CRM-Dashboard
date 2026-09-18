<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Notify once per order per reason" needs every reason already sent, not only
 * the last one (A → B → A must not re-notify A): replaces the single
 * `mismatch_notified_reason` with a JSON list, carrying existing values over.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'mismatch_notified_reasons')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->json('mismatch_notified_reasons')->nullable()->after('mismatch_reason');
            });
        }

        if (Schema::hasColumn('orders', 'mismatch_notified_reason')) {
            DB::table('orders')->whereNotNull('mismatch_notified_reason')->orderBy('id')->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('orders')->where('id', $row->id)
                        ->update(['mismatch_notified_reasons' => json_encode([$row->mismatch_notified_reason])]);
                }
            });

            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('mismatch_notified_reason');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('orders', 'mismatch_notified_reason')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('mismatch_notified_reason', 40)->nullable()->after('mismatch_reason');
            });
        }

        if (Schema::hasColumn('orders', 'mismatch_notified_reasons')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('mismatch_notified_reasons');
            });
        }
    }
};
