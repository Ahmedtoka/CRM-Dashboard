<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Double-publish guard (W2): a per-request idempotency key and an "in flight" key that is unique while a publication
     * of the same file + caption + ad set is queued, running or recently done. Both live in MariaDB, never in the cache.
     */
    public function up(): void
    {
        if (! Schema::hasTable('ad_publications')) {
            return;
        }

        if (! Schema::hasColumn('ad_publications', 'idempotency_key')) {
            Schema::table('ad_publications', fn (Blueprint $t) => $t->string('idempotency_key', 64)->nullable());
        }
        if (! Schema::hasColumn('ad_publications', 'open_key')) {
            Schema::table('ad_publications', fn (Blueprint $t) => $t->string('open_key', 64)->nullable()->unique('ad_publications_open_key_unique'));
        }
        if (! Schema::hasColumn('ad_publications', 'allow_duplicate')) {
            Schema::table('ad_publications', fn (Blueprint $t) => $t->boolean('allow_duplicate')->default(false));
        }
        if (! Schema::hasIndex('ad_publications', 'ad_publications_idem_unique')) {
            Schema::table('ad_publications', fn (Blueprint $t) => $t->unique(['created_by_id', 'idempotency_key', 'caption_index'], 'ad_publications_idem_unique'));
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ad_publications')) {
            return;
        }
        if (Schema::hasIndex('ad_publications', 'ad_publications_idem_unique')) {
            Schema::table('ad_publications', fn (Blueprint $t) => $t->dropUnique('ad_publications_idem_unique'));
        }
        if (Schema::hasColumn('ad_publications', 'open_key')) {
            Schema::table('ad_publications', fn (Blueprint $t) => $t->dropColumn('open_key'));
        }
        foreach (['idempotency_key', 'allow_duplicate'] as $c) {
            if (Schema::hasColumn('ad_publications', $c)) {
                Schema::table('ad_publications', fn (Blueprint $t) => $t->dropColumn($c));
            }
        }
    }
};
