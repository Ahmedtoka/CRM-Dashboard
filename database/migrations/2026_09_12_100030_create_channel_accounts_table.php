<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 30);
            $table->string('name');
            $table->string('external_id')->nullable();
            $table->string('driver', 20)->default('fake');
            $table->text('credentials')->nullable();
            $table->string('status', 20)->default('connected');
            $table->timestamp('last_webhook_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_accounts');
    }
};
