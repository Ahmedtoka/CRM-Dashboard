<?php

use Illuminate\Database\Migrations\Migration;

/** The English of the reworded contact question (owner, 2026-09-24); the seed only inserts what is missing. */
return new class extends Migration
{
    public function up(): void
    {
        (require __DIR__.'/2026_09_21_200040_seed_english_bot_translations.php')->up();
    }

    public function down(): void
    {
        // Data only.
    }
};
