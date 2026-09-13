<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix round 1: DATETIME(0)/second-precision columns (webhook_events.received_at,
 * messages.created_at) silently truncated sub-second precision, inflating measured
 * latency by up to 999ms. These plain bigint epoch-ms columns are the real source
 * of truth for the acceptance-critical start instants (spec §11.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            if (! Schema::hasColumn('webhook_events', 'received_at_ms')) {
                $table->unsignedBigInteger('received_at_ms')->nullable()->after('received_at');
            }
        });

        Schema::table('messages', function (Blueprint $table) {
            if (! Schema::hasColumn('messages', 'queued_at_ms')) {
                $table->unsignedBigInteger('queued_at_ms')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            if (Schema::hasColumn('webhook_events', 'received_at_ms')) {
                $table->dropColumn('received_at_ms');
            }
        });

        Schema::table('messages', function (Blueprint $table) {
            if (Schema::hasColumn('messages', 'queued_at_ms')) {
                $table->dropColumn('queued_at_ms');
            }
        });
    }
};
