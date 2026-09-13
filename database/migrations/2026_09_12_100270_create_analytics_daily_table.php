<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_daily', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('platform', 30)->nullable();
            $table->unsignedInteger('messages_sent')->default(0);
            $table->unsignedInteger('conversations_handled')->default(0);
            $table->unsignedInteger('first_responses')->default(0);
            $table->unsignedInteger('follow_ups')->default(0);
            $table->unsignedInteger('resolved')->default(0);
            $table->unsignedInteger('avg_first_response_sec')->default(0);
            $table->unsignedInteger('avg_response_sec')->default(0);
            $table->unsignedInteger('comments_handled')->default(0);
            $table->unsignedInteger('orders_count')->default(0);
            $table->decimal('orders_total', 12, 2)->default(0);
            $table->unsignedInteger('online_minutes')->default(0);
            $table->timestamps();

            $table->unique(['date', 'user_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_daily');
    }
};
