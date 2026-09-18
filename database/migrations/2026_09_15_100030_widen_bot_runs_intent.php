<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flow runs record every intent of a burst ("price,delivery_time") and
 * catalog keys such as "international_shipping" exceed the old 20 chars.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bot_runs') && Schema::hasColumn('bot_runs', 'intent')) {
            Schema::table('bot_runs', fn (Blueprint $t) => $t->string('intent', 191)->nullable()->change());
        }
    }

    public function down(): void
    {
        // Not narrowed again: existing flow rows may be longer than 20 chars.
    }
};
