<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ads_health_state')) {
            return;
        }

        Schema::create('ads_health_state', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('status', 10); // ok | warn | critical
            $table->dateTime('since');
            $table->string('notified_status', 10)->nullable();
            $table->dateTime('notified_at')->nullable();
            $table->json('detail')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ads_health_state');
    }
};
