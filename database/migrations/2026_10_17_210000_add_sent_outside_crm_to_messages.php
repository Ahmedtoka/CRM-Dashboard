<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A staff reply sent from Meta Business Suite or the phone app, recorded from its webhook
 * echo (2026-10-17). Appended last (no after()) so MySQL can add it instantly on a large table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $t) {
            if (! Schema::hasColumn('messages', 'sent_outside_crm')) {
                $t->boolean('sent_outside_crm')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $t) {
            if (Schema::hasColumn('messages', 'sent_outside_crm')) {
                $t->dropColumn('sent_outside_crm');
            }
        });
    }
};
