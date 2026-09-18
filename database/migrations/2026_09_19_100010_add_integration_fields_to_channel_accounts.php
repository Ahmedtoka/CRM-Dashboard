<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings → Integrations: what the cards show about a connected account.
 *
 * - profile:           non-secret display data (picture, @username, WhatsApp display
 *                      number / verified name / quality rating, WABA id).
 * - connected_at:      when the account was (re)connected from the Integrations page.
 * - health:            the last `channels:health` result ({status, checks[], checked_at}).
 * - health_status:     ok | warning | problem — kept separately so the transition into
 *                      "problem" (which notifies admins) is a cheap comparison.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('channel_accounts', 'profile')) {
                $table->json('profile')->nullable()->after('credentials');
            }
            if (! Schema::hasColumn('channel_accounts', 'connected_at')) {
                $table->timestamp('connected_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('channel_accounts', 'health')) {
                $table->json('health')->nullable()->after('last_error');
            }
            if (! Schema::hasColumn('channel_accounts', 'health_status')) {
                $table->string('health_status', 20)->nullable()->after('health');
            }
            if (! Schema::hasColumn('channel_accounts', 'health_checked_at')) {
                $table->timestamp('health_checked_at')->nullable()->after('health_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('channel_accounts', function (Blueprint $table) {
            foreach (['profile', 'connected_at', 'health', 'health_status', 'health_checked_at'] as $column) {
                if (Schema::hasColumn('channel_accounts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
