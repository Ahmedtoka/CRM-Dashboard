<?php

// database/migrations/2026_09_29_100000_create_queue_tables.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_settings', function (Blueprint $t) {
            $t->id();
            $t->boolean('enabled')->default(false);
            $t->unsignedTinyInteger('windows_per_moderator')->default(3);
            $t->unsignedInteger('silence_warn_seconds')->default(180);
            $t->unsignedInteger('silence_close_seconds')->default(300);
            $t->unsignedInteger('return_priority_minutes')->default(120);
            $t->unsignedInteger('close_confirm_minutes')->default(60);
            $t->unsignedInteger('sla_first_reply_seconds')->default(600);
            $t->unsignedTinyInteger('sla_target_pct')->default(90);
            $t->unsignedTinyInteger('occupancy_cap_pct')->default(80);
            $t->unsignedSmallInteger('break_minutes')->default(30);
            $t->unsignedSmallInteger('break_after_minutes')->default(180);
            $t->unsignedTinyInteger('review_sample_pct')->default(40);
            $t->unsignedInteger('review_delay_seconds')->default(60);
            $t->unsignedSmallInteger('case_sla_hours')->default(24);
            $t->boolean('night_message_enabled')->default(true);
            $t->unsignedInteger('speed_fast_seconds')->default(60);
            $t->unsignedInteger('speed_ok_seconds')->default(180);
            $t->unsignedInteger('eta_default_handle_seconds')->default(300);
            $t->json('points')->nullable();
            $t->json('shifts')->nullable();
            $t->json('default_roster')->nullable();
            $t->timestamps();
        });

        Schema::create('shifts', function (Blueprint $t) {
            $t->id();
            $t->date('date');
            $t->string('shift_key', 40);
            $t->string('name', 60);
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->foreignId('leader_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('opened_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('opened_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->string('status', 20)->default('planned');
            $t->json('settings_snapshot')->nullable();
            $t->timestamps();
            $t->unique(['date', 'shift_key']);
            $t->index(['status', 'starts_at']);
        });

        Schema::create('shift_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('status', 20)->default('available');
            $t->unsignedTinyInteger('windows_cap')->nullable();
            $t->timestamp('joined_at')->nullable();
            $t->timestamp('left_at')->nullable();
            $t->timestamp('break_at')->nullable();
            $t->timestamp('break_started_at')->nullable();
            $t->timestamp('break_ends_at')->nullable();
            $t->timestamp('last_heartbeat_at')->nullable();
            $t->json('stats')->nullable();
            $t->timestamps();
            $t->unique(['shift_id', 'user_id']);
        });

        Schema::create('queue_days', function (Blueprint $t) {
            $t->id();
            $t->date('date')->unique();
            $t->unsignedInteger('next_ticket')->default(1);
            $t->timestamp('opened_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->json('summary')->nullable();
            $t->timestamps();
        });

        Schema::create('queue_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->date('business_date');
            $t->unsignedInteger('ticket_no');
            $t->string('kind', 20)->default('unknown');
            $t->string('priority', 20)->default('live');
            $t->string('status', 20)->default('waiting');
            $t->foreignId('reserved_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedTinyInteger('window_no')->nullable();
            $t->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('shift_member_id')->nullable()->constrained('shift_members')->nullOnDelete();
            $t->dateTime('enqueued_at');
            $t->timestamp('called_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('first_reply_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamp('last_customer_message_at')->nullable();
            $t->timestamp('silence_warned_at')->nullable();
            $t->unsignedInteger('eta_seconds')->nullable();
            $t->unsignedInteger('position_at_enqueue')->nullable();
            $t->json('waiting_messages')->nullable();
            $t->string('close_reason', 30)->nullable();
            $t->foreignId('closed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('confirmed_at')->nullable();
            $t->foreignId('reopened_from_entry_id')->nullable()->constrained('queue_entries')->nullOnDelete();
            $t->unsignedTinyInteger('reopen_count')->default(0);
            $t->string('rule', 120)->nullable();
            $t->json('bot_summary')->nullable();
            $t->foreignId('support_case_id')->nullable()->constrained('support_cases')->nullOnDelete();
            $t->timestamp('return_priority_until')->nullable();
            $t->boolean('sla_met')->nullable();
            $t->unsignedInteger('wait_seconds')->nullable();
            $t->unsignedInteger('handle_seconds')->nullable();
            $t->boolean('is_test')->default(false);
            $t->timestamps();
            $t->unique(['business_date', 'ticket_no']);
            $t->index(['status', 'priority', 'enqueued_at']);
            $t->index(['assigned_user_id', 'status']);
            $t->index(['conversation_id', 'status']);
            $t->index('business_date');
        });

        Schema::create('queue_decisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $t->string('trigger', 120);
            $t->json('lines');
            $t->timestamp('created_at')->useCurrent();
            $t->index('created_at');
        });

        Schema::table('conversations', function (Blueprint $t) {
            $t->foreignId('assignee_id')->nullable()->after('locked_by_id')->constrained('users')->nullOnDelete();
            $t->timestamp('assigned_at')->nullable()->after('assignee_id');
            $t->foreignId('queue_entry_id')->nullable()->after('assigned_at')->constrained('queue_entries')->nullOnDelete();
            $t->timestamp('return_priority_until')->nullable()->after('queue_entry_id');
            $t->index(['assignee_id', 'status']);
        });
    }

    public function down(): void
    {
        // MySQL/MariaDB back the assignee foreign key with the (assignee_id, status) index, and
        // dropping the column alone would shrink that index to (status) and keep it. So: foreign
        // key, then index, then columns.
        Schema::table('conversations', function (Blueprint $t) {
            $t->dropForeign(['assignee_id']);
            $t->dropForeign(['queue_entry_id']);
        });
        Schema::table('conversations', function (Blueprint $t) {
            $t->dropIndex(['assignee_id', 'status']);
            $t->dropColumn(['assignee_id', 'assigned_at', 'queue_entry_id', 'return_priority_until']);
        });
        foreach (['queue_decisions', 'queue_entries', 'queue_days', 'shift_members', 'shifts', 'queue_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
