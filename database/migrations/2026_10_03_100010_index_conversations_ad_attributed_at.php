<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The ads overview and buyer pages filter conversations by `ad_attributed_at` on every load. */
    public function up(): void
    {
        if (! Schema::hasIndex('conversations', 'conversations_ad_attributed_at_index')) {
            Schema::table('conversations', fn (Blueprint $table) => $table->index('ad_attributed_at', 'conversations_ad_attributed_at_index'));
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('conversations', 'conversations_ad_attributed_at_index')) {
            Schema::table('conversations', fn (Blueprint $table) => $table->dropIndex('conversations_ad_attributed_at_index'));
        }
    }
};
