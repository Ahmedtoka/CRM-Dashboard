<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit A9: a WhatsApp template message keeps its template {name, language,
 * params} so a retry re-sends it as that template rather than as plain text.
 * Appended last (no after()) so MySQL can add it instantly on a large table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $t) {
            if (! Schema::hasColumn('messages', 'template')) {
                $t->json('template')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $t) {
            if (Schema::hasColumn('messages', 'template')) {
                $t->dropColumn('template');
            }
        });
    }
};
