<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** «ابدأ من هنا» (2026-09-26): when the admin chose to skip the automatic landing on the onboarding page. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->timestamp('onboarding_dismissed_at')->nullable()->after('store_url');
        });
    }

    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn('onboarding_dismissed_at');
        });
    }
};
