<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One DB claim per account for every sync kind (A5, F-052): AccountSyncClaim CAS on these two columns. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ad_accounts', 'sync_claim_key')) {
            Schema::table('ad_accounts', function (Blueprint $table) {
                $table->string('sync_claim_key', 36)->nullable();
            });
        }
        if (! Schema::hasColumn('ad_accounts', 'sync_claimed_until')) {
            Schema::table('ad_accounts', function (Blueprint $table) {
                $table->timestamp('sync_claimed_until')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['sync_claim_key', 'sync_claimed_until'] as $column) {
            if (Schema::hasColumn('ad_accounts', $column)) {
                Schema::table('ad_accounts', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
