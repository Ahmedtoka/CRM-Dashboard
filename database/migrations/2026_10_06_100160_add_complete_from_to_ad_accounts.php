<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * First day from which the account's control totals and ok syncs cover every day up to yesterday (A11). Written only
 * by ads:reconcile; null = unknown (never reconciled, or the latest day is not covered yet).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ad_accounts', 'complete_from')) {
            Schema::table('ad_accounts', function (Blueprint $table) {
                $table->date('complete_from')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ad_accounts', 'complete_from')) {
            Schema::table('ad_accounts', function (Blueprint $table) {
                $table->dropColumn('complete_from');
            });
        }
    }
};
