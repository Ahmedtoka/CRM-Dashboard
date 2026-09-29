<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Why the shift leader took a customer out of the lounge (live board, cancel with a reason). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('queue_entries', 'close_note')) {
            return;
        }

        Schema::table('queue_entries', function (Blueprint $t) {
            $t->string('close_note', 200)->nullable()->after('close_reason');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('queue_entries', 'close_note')) {
            return;
        }

        Schema::table('queue_entries', function (Blueprint $t) {
            $t->dropColumn('close_note');
        });
    }
};
