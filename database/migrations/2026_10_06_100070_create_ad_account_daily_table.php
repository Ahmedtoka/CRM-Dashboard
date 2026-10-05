<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account-level daily control totals (A2): every status counted, so they match Ads Manager. The first_* columns keep
 * the values of the first fetch so later restatements by Meta can be measured (R-07).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ad_account_daily')) {
            return;
        }

        Schema::create('ad_account_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_account_id')->constrained('ad_accounts')->cascadeOnDelete();
            $table->date('date');
            $table->decimal('spend', 14, 2)->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->decimal('purchases', 12, 2)->default(0);
            $table->decimal('purchase_value', 14, 2)->default(0);
            $table->string('currency', 10)->nullable();
            $table->decimal('first_spend', 14, 2)->default(0);
            $table->decimal('first_purchases', 12, 2)->default(0);
            $table->decimal('first_purchase_value', 14, 2)->default(0);
            $table->dateTime('first_fetched_at')->nullable();
            $table->dateTime('fetched_at')->nullable();
            $table->unique(['ad_account_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_account_daily');
    }
};
