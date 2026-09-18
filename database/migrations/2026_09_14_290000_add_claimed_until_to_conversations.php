<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('conversations', 'claimed_until')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->timestamp('claimed_until')->nullable()->after('locked_until');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('conversations', 'claimed_until')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->dropColumn('claimed_until');
            });
        }
    }
};
