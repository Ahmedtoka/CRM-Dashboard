<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One pending hourly sync per account (A5): set by ads:sync with a CAS, cleared when the job ends. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ad_accounts', 'sync_pending_since')) {
            Schema::table('ad_accounts', function (Blueprint $table) {
                $table->timestamp('sync_pending_since')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ad_accounts', 'sync_pending_since')) {
            Schema::table('ad_accounts', function (Blueprint $table) {
                $table->dropColumn('sync_pending_since');
            });
        }
    }
};
