<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bilingual bot (design 2026-09-21 §2): every Arabic text the bot sends, with its
 * translation per locale. `source_text` is the masked source (numbers, links, prices
 * and emoji replaced by ⟦n⟧ placeholders) so one row serves every order number, and
 * `source_hash` is its sha256. `origin` = human keeps an owner edit from ever being
 * overwritten by an automatic translation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_translations', function (Blueprint $table) {
            $table->id();
            $table->char('source_hash', 64);
            $table->text('source_text');
            $table->string('locale', 5);
            $table->text('text');
            $table->string('origin', 10)->default('auto');
            $table->string('context')->nullable();
            $table->timestamps();

            $table->unique(['source_hash', 'locale']);
            $table->index(['locale', 'origin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_translations');
    }
};
