<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ads_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('ad_platform_connections', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 20);
            $table->string('name');
            $table->text('credentials')->nullable();
            $table->string('status', 20)->default('connected');
            $table->text('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ad_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('ad_platform_connections')->cascadeOnDelete();
            $table->string('platform', 20);
            $table->string('external_id');
            $table->string('name');
            $table->string('currency', 10)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('status', 30)->nullable();
            $table->decimal('balance', 14, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['platform', 'external_id']);
        });

        Schema::create('media_buyers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('color', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('ad_account_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_account_id')->constrained('ad_accounts')->cascadeOnDelete();
            $table->foreignId('media_buyer_id')->constrained('media_buyers')->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->timestamps();
            $table->index(['ad_account_id', 'starts_on']);
        });

        Schema::create('ad_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_account_id')->constrained('ad_accounts')->cascadeOnDelete();
            $table->string('external_id');
            $table->string('name');
            $table->string('status', 30)->nullable();
            $table->string('objective', 60)->nullable();
            $table->timestamps();
            $table->unique(['ad_account_id', 'external_id']);
        });

        Schema::create('ad_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
            $table->string('external_id');
            $table->string('name');
            $table->string('status', 30)->nullable();
            $table->timestamps();
            $table->unique(['ad_campaign_id', 'external_id']);
        });

        Schema::create('ads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_account_id')->constrained('ad_accounts')->cascadeOnDelete();
            $table->foreignId('ad_campaign_id')->nullable()->constrained('ad_campaigns')->nullOnDelete();
            $table->foreignId('ad_set_id')->nullable()->constrained('ad_sets')->nullOnDelete();
            $table->string('external_id');
            $table->string('name');
            $table->string('status', 30)->nullable();
            $table->string('effective_status', 40)->nullable();
            $table->string('type', 20)->nullable();
            $table->text('headline')->nullable();
            $table->text('body')->nullable();
            $table->text('thumbnail_url')->nullable();
            $table->text('image_url')->nullable();
            $table->text('video_url')->nullable();
            $table->text('preview_url')->nullable();
            $table->longText('preview_html')->nullable();
            $table->text('permalink_url')->nullable();
            $table->text('instagram_permalink_url')->nullable();
            $table->string('object_story_id')->nullable();
            $table->json('carousel')->nullable();
            $table->text('url_tags')->nullable();
            $table->timestamp('created_time')->nullable();
            $table->timestamp('media_fetched_at')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
            $table->unique(['ad_account_id', 'external_id']);
            $table->index(['ad_account_id', 'effective_status']);
        });

        Schema::create('ad_daily_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_id')->constrained('ads')->cascadeOnDelete();
            $table->foreignId('ad_account_id')->constrained('ad_accounts')->cascadeOnDelete();
            $table->date('date');
            $table->decimal('spend', 14, 2)->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('reach')->default(0);
            $table->decimal('purchases', 12, 2)->default(0);
            $table->decimal('purchase_value', 14, 2)->default(0);
            $table->timestamps();
            $table->unique(['ad_id', 'date']);
            $table->index(['ad_account_id', 'date']);
        });

        Schema::create('buyer_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_buyer_id')->constrained('media_buyers')->cascadeOnDelete();
            $table->date('month');
            $table->decimal('budget', 14, 2)->default(0);
            $table->decimal('target_roas', 8, 2)->nullable();
            $table->timestamps();
            $table->unique(['media_buyer_id', 'month']);
        });

        Schema::create('ads_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_account_id')->nullable()->constrained('ad_accounts')->nullOnDelete();
            $table->string('platform', 20);
            $table->string('kind', 20);
            $table->string('status', 20)->default('running');
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->unsignedInteger('ads_count')->default(0);
            $table->unsignedInteger('rows_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['ads_sync_runs', 'buyer_targets', 'ad_daily_metrics', 'ads', 'ad_sets', 'ad_campaigns', 'ad_account_assignments', 'media_buyers', 'ad_accounts', 'ad_platform_connections', 'ads_settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
