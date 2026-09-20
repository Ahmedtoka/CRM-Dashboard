<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bilingual bot (design 2026-09-21 §1): the language the conversation is held in,
 * decided from the customer's own messages (App\Bot\Language\LanguageDetector).
 * Null until she writes something with letters in it; everything the bot sends
 * then follows it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('language', 2)->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('language');
        });
    }
};
