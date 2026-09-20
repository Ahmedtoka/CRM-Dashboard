<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Test-link bookkeeping on the rows the reports read (design 2026-09-21 §3/§4/§5).
 *
 * `is_test` marks every conversation and message that belongs to a `driver = test`
 * channel account, so the dashboard reports and the analytics rollup can leave them
 * out with a plain indexed predicate instead of joining to `channel_accounts` on
 * every aggregate. `source` records whether a learning note/suggestion came from a
 * live conversation or a team test.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->boolean('is_test')->default(false)->after('platform');
            $table->index(['is_test', 'created_at']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('is_test')->default(false)->after('platform');
            $table->index(['is_test', 'created_at']);
        });

        Schema::table('bot_learning_notes', function (Blueprint $table) {
            $table->string('source', 10)->default('live')->after('channel_account_id');
            $table->index(['source', 'created_at']);
        });

        Schema::table('bot_suggestions', function (Blueprint $table) {
            $table->string('source', 10)->default('live')->after('type');
        });

        // Nothing is a test conversation yet on an existing install, but a re-run of
        // this migration on a database that already has test accounts must stay true.
        $testAccounts = DB::table('channel_accounts')->where('driver', 'test')->pluck('id');

        if ($testAccounts->isNotEmpty()) {
            DB::table('conversations')->whereIn('channel_account_id', $testAccounts)->update(['is_test' => true]);
            DB::table('messages')
                ->whereIn('conversation_id', DB::table('conversations')->where('is_test', true)->select('id'))
                ->update(['is_test' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['is_test', 'created_at']);
            $table->dropColumn('is_test');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['is_test', 'created_at']);
            $table->dropColumn('is_test');
        });

        Schema::table('bot_learning_notes', function (Blueprint $table) {
            $table->dropIndex(['source', 'created_at']);
            $table->dropColumn('source');
        });

        Schema::table('bot_suggestions', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
