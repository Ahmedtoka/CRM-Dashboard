<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A case a moderator opens from her window (close reason `case`): who opened it, from which
 * queue ticket, and its resolution SLA. `resolved_at` / `resolved_by_id` are written by Part 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('support_cases', 'queue_entry_id')) {
            return;
        }

        Schema::table('support_cases', function (Blueprint $t) {
            $t->foreignId('opened_by_id')->nullable()->after('assigned_to_id')->constrained('users')->nullOnDelete();
            $t->foreignId('queue_entry_id')->nullable()->after('opened_by_id')->constrained('queue_entries')->nullOnDelete();
            $t->timestamp('sla_due_at')->nullable()->after('queue_entry_id');
            $t->timestamp('resolved_at')->nullable()->after('sla_due_at');
            $t->foreignId('resolved_by_id')->nullable()->after('resolved_at')->constrained('users')->nullOnDelete();
            $t->index('sla_due_at');
        });
    }

    public function down(): void
    {
        Schema::table('support_cases', function (Blueprint $t) {
            $t->dropIndex(['sla_due_at']);
            $t->dropConstrainedForeignId('resolved_by_id');
            $t->dropConstrainedForeignId('queue_entry_id');
            $t->dropConstrainedForeignId('opened_by_id');
            $t->dropColumn(['sla_due_at', 'resolved_at']);
        });
    }
};
