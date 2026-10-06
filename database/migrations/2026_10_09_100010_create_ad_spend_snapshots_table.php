<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Intraday account spend (S2 A3): the hourly sync's today-so-far control spend, one row per account per Cairo hour. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ad_spend_snapshots')) {
            return;
        }
        Schema::create('ad_spend_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_account_id')->constrained('ad_accounts')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedTinyInteger('hour');
            $table->decimal('spend', 14, 2)->default(0);
            $table->dateTime('captured_at');
            $table->unique(['ad_account_id', 'date', 'hour']);
            $table->index(['date', 'hour']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_spend_snapshots');
    }
};
