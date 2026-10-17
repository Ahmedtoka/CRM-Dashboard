<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moderator mobile app (spec 2026-10-16 §5): one row per phone that registered for push.
 * The FCM token is long, so it is kept as TEXT and looked up by its sha256 hex (token_hash,
 * unique). A row dies with its user and with the Sanctum token that registered it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('personal_access_token_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('platform', 16); // android | ios
            $t->text('token');
            $t->char('token_hash', 64)->unique();
            $t->string('app_version', 32)->nullable();
            $t->string('locale', 5)->nullable();
            $t->timestamp('last_registered_at')->nullable();
            $t->timestamp('last_push_at')->nullable();
            $t->unsignedSmallInteger('failure_count')->default(0);
            $t->timestamp('disabled_at')->nullable();
            $t->timestamps();

            $t->index(['user_id', 'disabled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
