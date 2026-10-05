<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ads_api_usage')) {
            return;
        }

        Schema::create('ads_api_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_account_id')->nullable()->constrained('ad_accounts')->nullOnDelete();
            $table->string('business_id', 40)->nullable();
            $table->string('header', 40); // x-business-use-case-usage | x-ad-account-usage
            $table->string('usage_type', 40)->nullable();
            $table->decimal('call_count', 5, 2)->default(0);
            $table->decimal('total_time', 5, 2)->default(0);
            $table->decimal('total_cputime', 5, 2)->default(0);
            $table->decimal('max_pct', 5, 2)->default(0);
            $table->unsignedInteger('regain_minutes')->default(0);
            $table->dateTime('recorded_at')->index();
            $table->index(['ad_account_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ads_api_usage');
    }
};
