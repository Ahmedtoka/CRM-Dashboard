<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('priority', 10)->default('normal')->after('status');
            $table->index('priority');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('is_spam')->default(false)->after('status');
            $table->boolean('is_low_value')->default(false)->after('is_spam');
        });

        Schema::table('customer_identities', function (Blueprint $table) {
            $table->boolean('spam_allowlisted')->default(false)->after('avatar_url');
        });

        Schema::table('bot_settings', function (Blueprint $table) {
            $table->json('spam_phrases')->nullable()->after('handover_keywords');
            $table->json('low_value_phrases')->nullable()->after('spam_phrases');
            $table->json('allowed_link_domains')->nullable()->after('low_value_phrases');
            $table->unsignedInteger('spam_repeat_threshold')->default(3)->after('allowed_link_domains');
        });
    }

    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn(['spam_phrases', 'low_value_phrases', 'allowed_link_domains', 'spam_repeat_threshold']);
        });

        Schema::table('customer_identities', function (Blueprint $table) {
            $table->dropColumn('spam_allowlisted');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['is_spam', 'is_low_value']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['priority']);
            $table->dropColumn('priority');
        });
    }
};
