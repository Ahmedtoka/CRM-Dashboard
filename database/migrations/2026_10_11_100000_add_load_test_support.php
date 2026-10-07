<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production load test (2026-10-07), additive and guarded (safe to re-run on MariaDB 10.4):
 * - channel_accounts.is_load_test: the three «تيست» channels; their sends never leave the system.
 * - conversations.meta: free json; `meta.load_test` holds a load-test chat's scenario and step.
 * - load_test_runs: the active plan (waves) and its counters, read by `crm:load-test tick`.
 * down() drops only these.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('channel_accounts') && ! Schema::hasColumn('channel_accounts', 'is_load_test')) {
            Schema::table('channel_accounts', fn (Blueprint $t) => $t->boolean('is_load_test')->default(false)->after('driver'));
        }

        if (Schema::hasTable('conversations') && ! Schema::hasColumn('conversations', 'meta')) {
            Schema::table('conversations', fn (Blueprint $t) => $t->json('meta')->nullable());
        }

        if (! Schema::hasTable('load_test_runs')) {
            Schema::create('load_test_runs', function (Blueprint $t) {
                $t->id();
                $t->string('status', 20)->default('active'); // active | stopped
                $t->json('plan')->nullable();                // {every, count, hours, spread}; null = no waves
                $t->timestamp('started_at')->nullable();
                $t->timestamp('waves_until')->nullable();
                $t->timestamp('next_wave_at')->nullable();
                $t->timestamp('stopped_at')->nullable();
                $t->unsignedInteger('waves_done')->default(0);
                $t->unsignedInteger('openers_sent')->default(0);
                $t->unsignedInteger('seeded')->default(0);
                $t->unsignedInteger('followups_sent')->default(0);
                $t->timestamps();
                $t->index(['status', 'next_wave_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('load_test_runs');

        if (Schema::hasTable('conversations') && Schema::hasColumn('conversations', 'meta')) {
            Schema::table('conversations', fn (Blueprint $t) => $t->dropColumn('meta'));
        }

        if (Schema::hasTable('channel_accounts') && Schema::hasColumn('channel_accounts', 'is_load_test')) {
            Schema::table('channel_accounts', fn (Blueprint $t) => $t->dropColumn('is_load_test'));
        }
    }
};
