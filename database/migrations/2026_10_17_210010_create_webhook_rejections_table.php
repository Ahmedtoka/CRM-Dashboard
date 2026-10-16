<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Webhooks refused at the door (bad Meta X-Hub-Signature-256, bad Shopify HMAC, 2026-10-17):
 * one counter row per provider per hour, so Settings can show "N refused in the last 24 hours"
 * without storing a row (or a payload) per request. App\Webhooks\WebhookRejections.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_rejections', function (Blueprint $t) {
            $t->id();
            $t->string('provider', 20);
            $t->dateTime('hour');
            $t->unsignedInteger('count')->default(0);
            $t->string('last_reason', 40)->nullable();
            $t->string('last_path', 120)->nullable();
            $t->dateTime('last_at')->nullable();
            $t->timestamps();

            $t->unique(['provider', 'hour']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_rejections');
    }
};
