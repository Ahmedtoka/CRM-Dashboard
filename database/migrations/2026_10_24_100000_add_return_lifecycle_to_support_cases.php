<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Returns and exchanges end to end (round 2026-10-24, x4): the lifecycle stage of a return/exchange case
 * (requested → pickup scheduled → picked up & refunded in cash → received at the warehouse → [exchange shipped]
 * → closed, or cancelled), the few typed fields the KPIs read, and one row per step in `support_case_events`
 * (who, when, what the customer was told). Guarded: every column/table is added only when missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_cases', function (Blueprint $table) {
            if (! Schema::hasColumn('support_cases', 'return_stage')) {
                $table->string('return_stage', 30)->nullable()->index()->after('status');
            }
            if (! Schema::hasColumn('support_cases', 'return_stage_at')) {
                $table->timestamp('return_stage_at')->nullable()->after('return_stage');
            }
            if (! Schema::hasColumn('support_cases', 'pickup_date')) {
                $table->date('pickup_date')->nullable()->after('return_stage_at');
            }
            if (! Schema::hasColumn('support_cases', 'refund_amount')) {
                $table->decimal('refund_amount', 10, 2)->nullable()->after('pickup_date');
            }
            if (! Schema::hasColumn('support_cases', 'exchange_order_id')) {
                $table->unsignedBigInteger('exchange_order_id')->nullable()->index()->after('refund_amount');
            }
        });

        if (! Schema::hasTable('support_case_events')) {
            Schema::create('support_case_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('support_case_id')->constrained('support_cases')->cascadeOnDelete();
                $table->string('stage', 30);
                $table->timestamp('happened_at');
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->json('meta')->nullable();
                // The customer-facing script that went out for this step, when it did (null = not told, see notify_error).
                $table->string('script_key', 60)->nullable();
                $table->timestamp('customer_notified_at')->nullable();
                $table->string('notify_error', 60)->nullable();
                $table->timestamps();
                $table->index(['support_case_id', 'happened_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('support_case_events');

        Schema::table('support_cases', function (Blueprint $table) {
            foreach (['return_stage', 'return_stage_at', 'pickup_date', 'refund_amount', 'exchange_order_id'] as $column) {
                if (Schema::hasColumn('support_cases', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
