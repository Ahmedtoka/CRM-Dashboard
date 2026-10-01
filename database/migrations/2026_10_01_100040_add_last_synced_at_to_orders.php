<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the order was last read from Shopify (webhook, sync, bulk import or
 * refresh), including a read that changed nothing. Spec §3.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'last_synced_at')) {
            Schema::table('orders', function (Blueprint $t) {
                $t->timestamp('last_synced_at')->nullable();
                $t->index('last_synced_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'last_synced_at')) {
            Schema::table('orders', function (Blueprint $t) {
                $t->dropIndex(['last_synced_at']);
                $t->dropColumn('last_synced_at');
            });
        }
    }
};
