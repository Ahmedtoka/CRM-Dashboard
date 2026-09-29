<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance (2026-09-29): moderators check themselves in and out. The log of it
 * (`queue_attendance_events`: in | break | back | out | auto_out, by whom when not herself),
 * who asked for a break or a check-out that waits for her windows
 * (`shift_members.requested_by_id`), and the once-per-break overrun alert
 * (`shift_members.break_overrun_alerted_at`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('queue_attendance_events')) {
            Schema::create('queue_attendance_events', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->foreignId('shift_id')->constrained()->cascadeOnDelete();
                $t->date('business_date');
                $t->string('event', 12);
                $t->dateTime('at');
                $t->foreignId('by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->index(['user_id', 'business_date']);
                $t->index(['business_date', 'at']);
            });
        }

        if (! Schema::hasColumn('shift_members', 'requested_by_id')) {
            Schema::table('shift_members', function (Blueprint $t) {
                $t->unsignedBigInteger('requested_by_id')->nullable()->after('status');
            });
        }

        if (! Schema::hasColumn('shift_members', 'break_overrun_alerted_at')) {
            Schema::table('shift_members', function (Blueprint $t) {
                $t->timestamp('break_overrun_alerted_at')->nullable()->after('break_ends_at');
            });
        }
    }

    public function down(): void
    {
        foreach (['break_overrun_alerted_at', 'requested_by_id'] as $column) {
            if (Schema::hasColumn('shift_members', $column)) {
                Schema::table('shift_members', function (Blueprint $t) use ($column) {
                    $t->dropColumn($column);
                });
            }
        }

        Schema::dropIfExists('queue_attendance_events');
    }
};
