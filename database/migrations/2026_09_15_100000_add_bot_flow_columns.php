<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $t) {
            if (! Schema::hasColumn('conversations', 'bot_due_at')) {
                $t->timestamp('bot_due_at')->nullable();
                $t->json('bot_state')->nullable();
                $t->string('priority_level', 10)->nullable();
                $t->string('handover_category', 40)->nullable();
                $t->string('queue', 10)->nullable();
                $t->index(['needs_human', 'priority_level', 'last_customer_message_at'], 'conv_queue_idx');
            }
        });

        Schema::table('bot_settings', function (Blueprint $t) {
            if (! Schema::hasColumn('bot_settings', 'burst_wait_seconds')) {
                $t->unsignedSmallInteger('burst_wait_seconds')->default(8);
                $t->unsignedSmallInteger('burst_max_wait_seconds')->default(25);
                $t->unsignedSmallInteger('typing_ms_per_char')->default(35);
                $t->boolean('order_lookup_enabled')->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $t) {
            $t->dropIndex('conv_queue_idx');
            $t->dropColumn(['bot_due_at', 'bot_state', 'priority_level', 'handover_category', 'queue']);
        });
        Schema::table('bot_settings', fn (Blueprint $t) => $t->dropColumn(['burst_wait_seconds', 'burst_max_wait_seconds', 'typing_ms_per_char', 'order_lookup_enabled']));
    }
};
