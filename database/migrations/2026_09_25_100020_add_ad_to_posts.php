<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comments on ads (owner, 2026-09-25): the ad behind a post — named by Instagram on the comment
 * webhook itself, found for a Facebook post by ClassifyAdPost (an unpublished "dark" post, or an
 * ad whose creative uses the post) — with the ad, ad set and campaign names from the Marketing API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('ad_id', 40)->nullable()->after('is_ad')->index();
            $table->string('ad_title', 190)->nullable()->after('ad_id');
            $table->string('ad_name', 190)->nullable()->after('ad_title');
            $table->string('ad_adset_name', 190)->nullable()->after('ad_name');
            $table->string('ad_campaign_name', 190)->nullable()->after('ad_adset_name');
            $table->timestamp('ad_checked_at')->nullable()->after('ad_campaign_name');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['ad_id', 'ad_title', 'ad_name', 'ad_adset_name', 'ad_campaign_name', 'ad_checked_at']);
        });
    }
};
