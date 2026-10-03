<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('utm_term')->nullable();
            $table->string('landing_site', 1000)->nullable();
            $table->foreignId('ad_id')->nullable()->constrained('ads')->nullOnDelete();
            $table->foreignId('ad_campaign_id')->nullable()->constrained('ad_campaigns')->nullOnDelete();
            $table->string('ad_attribution', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ad_id');
            $table->dropConstrainedForeignId('ad_campaign_id');
            $table->dropColumn(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'landing_site', 'ad_attribution']);
        });
    }
};
