<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_materials', function (Blueprint $table) {
            // null = follow the product's inventory; false = marked unavailable by hand; true = marked available by hand.
            $table->boolean('stock_override')->nullable()->after('need_stop_at');
        });
    }

    public function down(): void
    {
        Schema::table('ad_materials', function (Blueprint $table) {
            $table->dropColumn('stock_override');
        });
    }
};
