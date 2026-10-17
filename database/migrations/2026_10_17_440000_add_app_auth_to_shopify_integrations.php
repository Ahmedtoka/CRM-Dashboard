<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le Voile Shopify app (round 2026-10-17, D1/D2): expand-only. The single integration row learns
 * which way it is connected (the manual custom-app token, or a Partners app through token
 * exchange with expiring offline tokens), the manual credentials are kept aside for the rollback,
 * subscriptions say who manages them and every webhook records which secret verified it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_integrations', function (Blueprint $table) {
            $table->string('auth_mode', 20)->default('custom_token');
            $table->string('app_client_id', 64)->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('refresh_token_expires_at')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('uninstalled_at')->nullable();
            $table->text('legacy_credentials')->nullable();
        });

        Schema::table('shopify_webhook_subscriptions', function (Blueprint $table) {
            $table->string('managed_by', 10)->default('api');
        });

        Schema::table('webhook_events', function (Blueprint $table) {
            $table->string('verified_with', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', fn (Blueprint $table) => $table->dropColumn('verified_with'));
        Schema::table('shopify_webhook_subscriptions', fn (Blueprint $table) => $table->dropColumn('managed_by'));
        Schema::table('shopify_integrations', fn (Blueprint $table) => $table->dropColumn([
            'auth_mode', 'app_client_id', 'access_token_expires_at', 'refresh_token', 'refresh_token_expires_at',
            'installed_at', 'uninstalled_at', 'legacy_credentials',
        ]));
    }
};
