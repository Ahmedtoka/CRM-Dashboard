<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which ad the conversation came from (owner, 2026-09-25): Meta's `referral` on the first
 * message of a Click-to-Messenger / Click-to-Instagram ad (ad id, the ad's title and picture,
 * the post), then the ad, ad set and campaign names read from the Marketing API when the
 * account's token can. Set once, on the first referral.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('ad_id', 40)->nullable()->after('source_comment_id')->index();
            $table->string('ad_title', 190)->nullable()->after('ad_id');
            $table->string('ad_name', 190)->nullable()->after('ad_title');
            $table->string('ad_adset_name', 190)->nullable()->after('ad_name');
            $table->string('ad_campaign_name', 190)->nullable()->after('ad_adset_name');
            $table->string('ad_post_id', 60)->nullable()->after('ad_campaign_name');
            $table->string('ad_photo_url', 500)->nullable()->after('ad_post_id');
            $table->string('ad_ref', 190)->nullable()->after('ad_photo_url');
            $table->timestamp('ad_attributed_at')->nullable()->after('ad_ref');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['ad_id', 'ad_title', 'ad_name', 'ad_adset_name', 'ad_campaign_name', 'ad_post_id', 'ad_photo_url', 'ad_ref', 'ad_attributed_at']);
        });
    }
};
