<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TikTok ad comments (E1, 2026-10-17). posts.platform_meta: what a reply needs on that platform (TikTok: advertiser,
 * ad, video item and the ad's identity). comments.public_reply_external_id: the id of our own public reply, so a poll
 * that lists it back never ingests it as a customer comment. Appended last (no after()) for an instant MySQL add.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $t) {
            if (! Schema::hasColumn('posts', 'platform_meta')) {
                $t->json('platform_meta')->nullable();
            }
        });
        Schema::table('comments', function (Blueprint $t) {
            if (! Schema::hasColumn('comments', 'public_reply_external_id')) {
                $t->string('public_reply_external_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $t) {
            if (Schema::hasColumn('comments', 'public_reply_external_id')) {
                $t->dropIndex(['public_reply_external_id']);
                $t->dropColumn('public_reply_external_id');
            }
        });
        Schema::table('posts', function (Blueprint $t) {
            if (Schema::hasColumn('posts', 'platform_meta')) {
                $t->dropColumn('platform_meta');
            }
        });
    }
};
