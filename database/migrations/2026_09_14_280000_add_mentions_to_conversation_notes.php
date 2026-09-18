<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('conversation_notes', 'mentions')) {
            Schema::table('conversation_notes', function (Blueprint $table) {
                $table->json('mentions')->nullable()->after('body');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('conversation_notes', 'mentions')) {
            Schema::table('conversation_notes', function (Blueprint $table) {
                $table->dropColumn('mentions');
            });
        }
    }
};
