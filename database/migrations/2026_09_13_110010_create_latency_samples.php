<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('latency_samples', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20); // inbound|outbound|list
            $table->string('ref');
            $table->dateTime('started_at', 3);
            $table->dateTime('ended_at', 3);
            $table->unsignedInteger('duration_ms');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('latency_samples');
    }
};
