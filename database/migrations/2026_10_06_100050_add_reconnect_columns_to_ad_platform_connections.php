<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['needs_reconnect_at', 'probed_at'] as $column) {
            if (! Schema::hasColumn('ad_platform_connections', $column)) {
                Schema::table('ad_platform_connections', fn (Blueprint $t) => $t->timestamp($column)->nullable());
            }
        }
    }

    public function down(): void
    {
        foreach (['needs_reconnect_at', 'probed_at'] as $column) {
            if (Schema::hasColumn('ad_platform_connections', $column)) {
                Schema::table('ad_platform_connections', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
