<?php

use App\Bot\Language\TranslationsCommand;
use App\Models\BotTranslation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The reviewed English of the bot's own texts (design 2026-09-21 §2), from
 * database/seeders/data/bot_translations_en.php. Data-only and idempotent: a row the
 * owner has edited (origin `human`) is never touched, and a row that already holds the
 * same text is left alone, so this can run again after the file is regenerated.
 *
 * Without it an English customer would wait for a model call on her very first message.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bot_translations')) {
            return;
        }

        foreach (['en'] as $locale) {
            $path = TranslationsCommand::path($locale);

            if (! is_file($path)) {
                continue;
            }

            $now = now();
            $rows = [];

            foreach ((array) require $path as $source => $text) {
                $source = (string) $source;
                $text = trim((string) $text);

                if ($source === '' || $text === '') {
                    continue;
                }

                $rows[] = [
                    'source_hash' => BotTranslation::hash($source),
                    'source_text' => $source,
                    'locale' => $locale,
                    'text' => $text,
                    'origin' => BotTranslation::ORIGIN_AUTO,
                    'context' => 'seed',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 200) as $chunk) {
                $hashes = array_column($chunk, 'source_hash');
                $existing = DB::table('bot_translations')->where('locale', $locale)->whereIn('source_hash', $hashes)->pluck('source_hash')->all();
                $new = array_values(array_filter($chunk, fn (array $row) => ! in_array($row['source_hash'], $existing, true)));

                if ($new !== []) {
                    DB::table('bot_translations')->insert($new);
                }
            }
        }
    }

    public function down(): void
    {
        // Data fix: left in place (the owner may have edited it since).
    }
};
