<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Open slots (spec 3.2): an ad set the account's buyer or a manager opened for content drafts. */
    public function up(): void
    {
        if (! Schema::hasTable('ad_sets') || Schema::hasColumn('ad_sets', 'open_for_drafts_at')) {
            return;
        }
        Schema::table('ad_sets', function (Blueprint $table) {
            $table->timestamp('open_for_drafts_at')->nullable()->index();
            $table->foreignId('open_for_drafts_by_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ad_sets') || ! Schema::hasColumn('ad_sets', 'open_for_drafts_at')) {
            return;
        }
        Schema::table('ad_sets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('open_for_drafts_by_id');
            $table->dropIndex(['open_for_drafts_at']);
            $table->dropColumn('open_for_drafts_at');
        });
    }
};
