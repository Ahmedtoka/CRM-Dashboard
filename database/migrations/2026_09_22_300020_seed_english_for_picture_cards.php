<?php

use Illuminate\Database\Migrations\Migration;

/**
 * The English of the 2026-09-22 texts (picture cards for the order's pieces, the exchange
 * product picker, the complaint's pieces), added to database/seeders/data/bot_translations_en.php.
 * Re-runs the seed migration, which only inserts sources that are not there yet.
 */
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
