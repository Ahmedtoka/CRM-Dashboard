<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL already creates an index for each foreign key; sqlite does not. Only add what is missing.
        foreach (['ad_id', 'ad_campaign_id'] as $column) {
            if (! Schema::hasIndex('orders', [$column])) {
                Schema::table('orders', fn (Blueprint $table) => $table->index($column, "orders_{$column}_index"));
            }
        }
    }

    public function down(): void
    {
        foreach (['ad_id', 'ad_campaign_id'] as $column) {
            if (Schema::hasIndex('orders', "orders_{$column}_index")) {
                Schema::table('orders', fn (Blueprint $table) => $table->dropIndex("orders_{$column}_index"));
            }
        }
    }
};
